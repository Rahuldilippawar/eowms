<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\RenewalItem;
use App\Models\Vehicle;
use App\Models\VehicleFuelLog;
use App\Models\VehicleService;
use App\Models\VehicleTrip;
use App\Services\AttendanceRules;
use App\Services\VehicleImport;
use App\Services\Vehicles\VehicleBook;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vehicles with their trips, fuel fills and services (the old app's vehicle
 * module). Drivers log for their own vehicle; corrections of logged entries
 * go through VehicleCorrectionController.
 */
class VehicleController extends Controller
{
    public function __construct(private readonly VehicleBook $book) {}

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $today = $this->today();
        $status = $f['status'] ?? 'active';
        $vehicles = $this->book->visible()->with(['department:id,name,code', 'driver:id,name', 'renewals' => fn ($q) => $q->where('is_active', true)])
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('vehicle_number', 'like', $like)->orWhere('model', 'like', $like)->orWhere('vehicle_type', 'like', $like));
            })
            ->orderBy('vehicle_number')->limit(1000)->get();
        $nextServices = $this->nextServices($vehicles->pluck('id')->all());

        if (($f['format'] ?? null) === 'csv') {
            abort_unless(AccessContext::current()->can('vehicles.export'), 403, __('auth.forbidden'));
            AuditLogger::record('vehicles', 'export', null, null, ['rows' => $vehicles->count()]);

            return Csv::download('vehicles.csv',
                ['vehicle_number', 'type', 'model', 'department', 'driver', 'fuel_type', 'odometer', ...array_map(fn ($k) => "{$k}_validity", Vehicle::RENEWAL_FIELDS), 'next_service_date', 'active'],
                $vehicles->map(function (Vehicle $v) use ($nextServices) {
                    $dates = $v->renewals->pluck('expiry_date', 'renewal_field');

                    return [
                        $v->vehicle_number, $v->vehicle_type, $v->model, $v->department?->name, $v->driver?->name, $v->fuel_type, (float) $v->current_odometer,
                        ...array_map(fn ($k) => $dates->get($k)?->toDateString(), Vehicle::RENEWAL_FIELDS),
                        ($nextServices[$v->id] ?? null)?->next_due_date?->toDateString(), $v->is_active ? 'yes' : 'no',
                    ];
                }));
        }

        return response()->json([
            'data' => $vehicles->map(fn (Vehicle $v) => $this->present($v, $today, $nextServices[$v->id] ?? null))->values(),
            'meta' => ['can_create' => AccessContext::current()->can('vehicles.manage'), 'can_export' => AccessContext::current()->can('vehicles.export'), 'fuel_types' => Vehicle::FUEL_TYPES],
        ]);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $vehicle = $this->book->visible()->with(['department:id,name,code', 'driver:id,name', 'renewals'])->findOrFail($vehicle->id);
        $today = $this->today();
        $trips = $vehicle->trips()->with('creator:id,name')->orderByDesc('trip_date')->orderByDesc('id')->limit(30)->get();
        $fuel = $vehicle->fuelLogs()->with('creator:id,name')->orderByDesc('fill_date')->orderByDesc('id')->limit(30)->get();
        $services = $vehicle->services()->with('creator:id,name')->orderByDesc('service_date')->orderByDesc('id')->limit(30)->get();
        $km = (float) $vehicle->trips()->sum('distance_km');
        $liters = (float) $vehicle->fuelLogs()->sum('liters');

        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $today->startOfMonth()->subMonths($i);
            $range = [$month->toDateString(), $month->endOfMonth()->toDateString()];
            $trend[] = [
                'month' => $month->format('Y-m'),
                'km' => (float) $vehicle->trips()->whereBetween('trip_date', $range)->sum('distance_km'),
                'liters' => (float) $vehicle->fuelLogs()->whereBetween('fill_date', $range)->sum('liters'),
            ];
        }

        return response()->json(['data' => $this->present($vehicle, $today, $services->first()) + [
            'chassis_no' => $vehicle->chassis_no, 'engine_no' => $vehicle->engine_no, 'vehicle_class' => $vehicle->vehicle_class,
            'passenger_capacity' => $vehicle->passenger_capacity, 'registration_date' => $vehicle->registration_date?->toDateString(),
            'documents' => collect(Vehicle::RENEWAL_FIELDS)->map(function ($field) use ($vehicle, $today) {
                $item = $vehicle->renewals->firstWhere('renewal_field', $field);
                $days = $item?->daysLeft($today);

                return ['field' => $field, 'renewal_id' => $item?->id, 'expiry_date' => $item?->expiry_date->toDateString(), 'days_left' => $days,
                    'status' => $item === null ? null : ($item->is_active ? RenewalItem::statusFor($days) : 'inactive')];
            })->values(),
            'stats' => [
                'total_km' => $km, 'total_liters' => $liters, 'km_per_liter' => $liters > 0 ? round($km / $liters, 2) : null,
                'fuel_cost' => round((float) $vehicle->fuelLogs()->sum('cost'), 2), 'service_cost' => round((float) $vehicle->services()->sum('cost'), 2),
            ],
            'trend' => $trend,
            'trips' => $trips->map(fn (VehicleTrip $t) => $this->entry('trip', $t))->values(),
            'fuel' => $fuel->map(fn (VehicleFuelLog $l) => $this->entry('fuel', $l))->values(),
            'services' => $services->map(fn (VehicleService $s) => $this->entry('service', $s))->values(),
            'pending_corrections' => DB::table('vehicle_correction_requests')->where('vehicle_id', $vehicle->id)->where('status', 'pending')
                ->get(['entry_type', 'entry_id'])->map(fn ($r) => "{$r->entry_type}:{$r->entry_id}")->values(),
            'can_manage' => $this->book->canManage($vehicle),
            'can_log' => $this->book->canLog($vehicle),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        [$data, $dates] = $this->validated($request);
        $vehicle = DB::transaction(function () use ($data, $dates, $request) {
            $vehicle = Vehicle::create($data + ['created_by' => $request->user()->id]);
            $this->book->syncRenewals($vehicle, $dates, $request->user()->id);
            AuditLogger::record('vehicles', 'create', $vehicle, null, AuditLogger::snapshot($vehicle));

            return $vehicle;
        });

        return $this->show($vehicle)->setStatusCode(201);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle = $this->managed($vehicle);
        [$data, $dates] = $this->validated($request, $vehicle);
        DB::transaction(function () use ($vehicle, $data, $dates, $request) {
            $before = $vehicle->getAttributes();
            $moved = (int) $data['department_id'] !== $vehicle->department_id;
            $vehicle->fill($data)->save();
            if ($moved || $vehicle->wasChanged('vehicle_number')) {
                $this->book->moveDepartment($vehicle);
                RenewalItem::query()->where('vehicle_id', $vehicle->id)->get()->each(fn (RenewalItem $r) => $r->forceFill([
                    'item_name' => "Vehicle {$vehicle->vehicle_number} — ".VehicleBook::RENEWAL_LABELS[$r->renewal_field], 'reference_no' => $vehicle->vehicle_number,
                ])->save());
            }
            $this->book->syncRenewals($vehicle, $dates, $request->user()->id);
            AuditLogger::recordChanges('vehicles', 'update', $vehicle, $before);
        });

        return $this->show($vehicle);
    }

    public function setActive(Request $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle = $this->managed($vehicle);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($vehicle, $data) {
            $before = $vehicle->getAttributes();
            $vehicle->forceFill(['is_active' => $data['is_active']])->save();
            // A retired vehicle's documents stop reminding; bringing it back resumes them.
            RenewalItem::query()->where('vehicle_id', $vehicle->id)->update(['is_active' => $data['is_active']]);
            AuditLogger::recordChanges('vehicles', $data['is_active'] ? 'activate' : 'deactivate', $vehicle, $before);
        });

        return $this->show($vehicle);
    }

    public function storeTrip(Request $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle = $this->loggable($vehicle);
        $data = $this->tripData($request, $vehicle);
        DB::transaction(function () use ($vehicle, $data, $request) {
            $trip = VehicleTrip::create($data + ['vehicle_id' => $vehicle->id, 'driver_user_id' => $vehicle->driver_user_id, 'created_by' => $request->user()->id]);
            $this->book->bumpOdometer($vehicle, (float) $trip->end_odometer);
            AuditLogger::record('vehicles', 'trip', $vehicle, null, ['trip' => $trip->id, 'km' => $trip->distance_km]);
        });

        return $this->show($vehicle)->setStatusCode(201);
    }

    public function storeFuel(Request $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle = $this->loggable($vehicle);
        $data = $this->fuelData($request);
        DB::transaction(function () use ($vehicle, $data, $request) {
            $fuel = VehicleFuelLog::create($data + ['vehicle_id' => $vehicle->id, 'created_by' => $request->user()->id]);
            $this->book->bumpOdometer($vehicle, (float) $fuel->odometer_reading);
            $this->book->charge($vehicle, $fuel, $request->user()->id);
            AuditLogger::record('vehicles', 'fuel', $vehicle, null, ['fuel' => $fuel->id, 'liters' => $fuel->liters, 'cost' => $fuel->cost]);
        });

        return $this->show($vehicle)->setStatusCode(201);
    }

    public function storeService(Request $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle = $this->loggable($vehicle);
        $data = $this->serviceData($request);
        DB::transaction(function () use ($vehicle, $data, $request) {
            $service = VehicleService::create($data + ['vehicle_id' => $vehicle->id, 'created_by' => $request->user()->id]);
            if ($service->odometer_reading !== null) {
                $this->book->bumpOdometer($vehicle, (float) $service->odometer_reading);
            }
            $this->book->charge($vehicle, $service, $request->user()->id);
            AuditLogger::record('vehicles', 'service', $vehicle, null, ['service' => $service->id, 'cost' => $service->cost]);
        });

        return $this->show($vehicle)->setStatusCode(201);
    }

    /** A vehicle manager fixes an entry directly; others ask through a correction request. */
    public function updateEntry(Request $request, Vehicle $vehicle, string $type, int $entry): JsonResponse
    {
        $vehicle = $this->managed($vehicle);
        $model = $this->findEntry($vehicle, $type, $entry);
        $this->applyEdit($vehicle, $model, $request, $request->user()->id);

        return $this->show($vehicle);
    }

    public function destroyEntry(Vehicle $vehicle, string $type, int $entry): JsonResponse
    {
        $vehicle = $this->managed($vehicle);
        $model = $this->findEntry($vehicle, $type, $entry);
        $this->applyDelete($vehicle, $model);

        return $this->show($vehicle);
    }

    /** All entries in a date range (the old PDF / Excel report), as JSON or CSV. */
    public function report(Request $request, Vehicle $vehicle): JsonResponse|StreamedResponse
    {
        $vehicle = $this->book->visible()->findOrFail($vehicle->id);
        $today = $this->today();
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $from = $f['from'] ?? $today->startOfMonth()->subMonths(2)->toDateString();
        $to = $f['to'] ?? $today->toDateString();
        $trips = $vehicle->trips()->whereBetween('trip_date', [$from, $to])->orderBy('trip_date')->orderBy('id')->get();
        $fuel = $vehicle->fuelLogs()->whereBetween('fill_date', [$from, $to])->orderBy('fill_date')->orderBy('id')->get();
        $services = $vehicle->services()->whereBetween('service_date', [$from, $to])->orderBy('service_date')->orderBy('id')->get();

        if (($f['format'] ?? null) === 'csv') {
            abort_unless(AccessContext::current()->can('vehicles.export'), 403, __('auth.forbidden'));
            AuditLogger::record('vehicles', 'report_export', $vehicle, null, ['from' => $from, 'to' => $to]);
            $rows = collect()
                ->concat($trips->map(fn (VehicleTrip $t) => [$t->trip_date->toDateString(), 'trip', "{$t->from_location} → {$t->to_location}", (float) $t->start_odometer, (float) $t->end_odometer, (float) $t->distance_km, '', '', $t->purpose]))
                ->concat($fuel->map(fn (VehicleFuelLog $l) => [$l->fill_date->toDateString(), 'fuel', $l->fuel_station, (float) $l->odometer_reading, '', '', (float) $l->liters, $l->cost === null ? '' : (float) $l->cost, '']))
                ->concat($services->map(fn (VehicleService $s) => [$s->service_date->toDateString(), 'service', $s->type, $s->odometer_reading === null ? '' : (float) $s->odometer_reading, '', '', '', $s->cost === null ? '' : (float) $s->cost, $s->description]))
                ->sortBy(fn ($r) => $r[0])->values();

            return Csv::download("vehicle-{$vehicle->vehicle_number}-{$from}-{$to}.csv",
                ['date', 'entry', 'details', 'odometer', 'end_odometer', 'km', 'liters', 'cost', 'notes'], $rows);
        }
        $km = (float) $trips->sum('distance_km');
        $liters = (float) $fuel->sum('liters');

        return response()->json(['data' => [
            'vehicle' => ['id' => $vehicle->id, 'vehicle_number' => $vehicle->vehicle_number, 'model' => $vehicle->model],
            'from' => $from, 'to' => $to,
            'totals' => ['km' => $km, 'liters' => $liters, 'km_per_liter' => $liters > 0 ? round($km / $liters, 2) : null,
                'fuel_cost' => round((float) $fuel->sum('cost'), 2), 'service_cost' => round((float) $services->sum('cost'), 2), 'trips' => $trips->count()],
            'trips' => $trips->map(fn (VehicleTrip $t) => $this->entry('trip', $t))->values(),
            'fuel' => $fuel->map(fn (VehicleFuelLog $l) => $this->entry('fuel', $l))->values(),
            'services' => $services->map(fn (VehicleService $s) => $this->entry('service', $s))->values(),
        ]]);
    }

    public function importTemplate(): StreamedResponse
    {
        return Csv::download('vehicle-import-template.csv', VehicleImport::COLUMNS,
            [['MH12AB1234', 'TRP', 'Bus', 'Tata Starbus', '2022-06-01', '', '', '', '40', 'diesel', '45200', 'EMP-001', '2027-05-31', '', '2027-03-31', '', '', '', '']]);
    }

    public function importPreview(Request $request, VehicleImport $import): JsonResponse
    {
        $checked = $import->check($this->importRows($request));

        return response()->json(['data' => ['rows' => $checked, 'total' => count($checked), 'with_errors' => collect($checked)->filter(fn ($r) => $r['errors'] !== [])->count()]]);
    }

    public function import(Request $request, VehicleImport $import): JsonResponse
    {
        $checked = $import->check($this->importRows($request));
        $bad = collect($checked)->filter(fn ($r) => $r['errors'] !== [])->values();
        if ($bad->isNotEmpty()) {
            return response()->json(['message' => __('import.has_errors'), 'errors' => ['file' => [__('import.has_errors')]], 'rows' => $bad], 422);
        }

        return response()->json(['data' => ['imported' => $import->import($checked, $request->user())]], 201);
    }

    /** @return list<array<string, string>> */
    private function importRows(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);

        return CsvReader::rows($request->file('file'), VehicleImport::REQUIRED, 500);
    }

    public function applyEdit(Vehicle $vehicle, Model $model, Request $request, int $userId): void
    {
        $data = match (true) {
            $model instanceof VehicleTrip => $this->tripData($request, $vehicle),
            $model instanceof VehicleFuelLog => $this->fuelData($request),
            default => $this->serviceData($request),
        };
        DB::transaction(function () use ($vehicle, $model, $data, $userId) {
            $before = $model->getAttributes();
            $model->fill($data)->save();
            $reading = $model instanceof VehicleTrip ? $model->end_odometer : $model->odometer_reading;
            if ($reading !== null) {
                $this->book->bumpOdometer($vehicle, (float) $reading);
            }
            $this->book->charge($vehicle, $model, $userId);
            AuditLogger::recordChanges('vehicles', 'entry_update', $model, $before);
        });
    }

    public function applyDelete(Vehicle $vehicle, Model $model): void
    {
        DB::transaction(function () use ($model) {
            AuditLogger::record('vehicles', 'entry_delete', $model, AuditLogger::snapshot($model));
            $this->book->uncharge($model);
            $model->delete();
        });
    }

    public function findEntry(Vehicle $vehicle, string $type, int $id): Model
    {
        abort_unless(isset(VehicleBook::ENTRY_MODELS[$type]), 404);

        return VehicleBook::ENTRY_MODELS[$type]::query()->where('vehicle_id', $vehicle->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function tripData(Request $request, Vehicle $vehicle): array
    {
        $data = $request->validate([
            'trip_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()->toDateString()],
            'from_location' => ['required', 'string', 'max:255'],
            'to_location' => ['required', 'string', 'max:255'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'start_odometer' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'end_odometer' => ['required', 'numeric', 'gte:start_odometer', 'max:99999999'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ]);

        return $data + ['distance_km' => round((float) $data['end_odometer'] - (float) $data['start_odometer'], 1)];
    }

    /** @return array<string, mixed> */
    private function fuelData(Request $request): array
    {
        return $request->validate([
            'fill_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()->toDateString()],
            'odometer_reading' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'liters' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'fuel_station' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, mixed> */
    private function serviceData(Request $request): array
    {
        return $request->validate([
            'service_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()->toDateString()],
            'type' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'odometer_reading' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'next_due_date' => ['nullable', 'date_format:Y-m-d', 'after:service_date'],
            'next_due_km' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'vendor' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string|null>} */
    private function validated(Request $request, ?Vehicle $vehicle = null): array
    {
        $orgId = AccessContext::current()->organizationId();
        if (is_string($request->input('vehicle_number'))) {
            $request->merge(['vehicle_number' => mb_strtoupper(preg_replace('/\s+/', '', $request->input('vehicle_number')))]);
        }
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'vehicle_number' => ['required', 'string', 'max:50', Rule::unique('vehicles')->where('organization_id', $orgId)->ignore($vehicle?->id)],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'registration_date' => ['nullable', 'date_format:Y-m-d'],
            'chassis_no' => ['nullable', 'string', 'max:100'],
            'engine_no' => ['nullable', 'string', 'max:100'],
            'vehicle_class' => ['nullable', 'string', 'max:100'],
            'passenger_capacity' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'driver_user_id' => ['nullable', 'integer'],
            'fuel_type' => ['required', Rule::in(Vehicle::FUEL_TYPES)],
            'current_odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'documents' => ['nullable', 'array'],
            ...collect(Vehicle::RENEWAL_FIELDS)->mapWithKeys(fn ($f) => ["documents.{$f}" => ['nullable', 'date_format:Y-m-d']])->all(),
        ]);
        if (! ScopeDepartments::reaches(AccessContext::current()->scope('vehicles.manage'), (int) $data['department_id'])) {
            throw ValidationException::withMessages(['department_id' => [__('access.outside_scope')]]);
        }
        if (! empty($data['driver_user_id']) && ! Employee::query()->where('user_id', $data['driver_user_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['driver_user_id' => [__('letters.choose_recipient')]]);
        }
        $dates = $data['documents'] ?? [];
        unset($data['documents']);
        // The odometer is set on creation; afterwards trips and fills move it.
        if ($vehicle !== null) {
            unset($data['current_odometer']);
        } else {
            $data['current_odometer'] ??= 0;
        }

        return [$data + ['institution_id' => Department::query()->whereKey($data['department_id'])->value('institution_id')], $dates];
    }

    private function managed(Vehicle $vehicle): Vehicle
    {
        $vehicle = $this->book->visible()->findOrFail($vehicle->id);
        abort_unless($this->book->canManage($vehicle), 403, __('auth.forbidden'));

        return $vehicle;
    }

    private function loggable(Vehicle $vehicle): Vehicle
    {
        $vehicle = $this->book->visible()->findOrFail($vehicle->id);
        abort_unless($this->book->canLog($vehicle), 403, __('auth.forbidden'));
        if (! $vehicle->is_active) {
            throw ValidationException::withMessages(['vehicle' => [__('vehicles.inactive')]]);
        }

        return $vehicle;
    }

    /** @param  list<int>  $ids  @return array<int, VehicleService> the latest service of each vehicle */
    private function nextServices(array $ids): array
    {
        return VehicleService::query()->whereIn('vehicle_id', $ids ?: [0])->orderBy('service_date')->orderBy('id')->get()->keyBy('vehicle_id')->all();
    }

    /** @return array<string, mixed> */
    private function present(Vehicle $v, CarbonImmutable $today, ?VehicleService $last): array
    {
        $documents = $v->renewals->where('is_active', true);
        $soonest = $documents->sortBy('expiry_date')->first();
        $serviceDays = $last?->next_due_date ? (int) round($today->diffInDays(CarbonImmutable::parse($last->next_due_date->toDateString()))) : null;
        $kmLeft = $last !== null && (float) $last->next_due_km > 0 ? round((float) $last->next_due_km - (float) $v->current_odometer, 1) : null;

        return [
            'id' => $v->id,
            'vehicle_number' => $v->vehicle_number,
            'vehicle_type' => $v->vehicle_type,
            'model' => $v->model,
            'department' => $v->department ? ['id' => $v->department->id, 'name' => $v->department->name] : null,
            'driver' => $v->driver ? ['id' => $v->driver->id, 'name' => $v->driver->name] : null,
            'fuel_type' => $v->fuel_type,
            'current_odometer' => (float) $v->current_odometer,
            'is_active' => $v->is_active,
            'documents_status' => $soonest ? RenewalItem::statusFor($soonest->daysLeft($today)) : null,
            'next_document' => $soonest ? ['field' => $soonest->renewal_field, 'expiry_date' => $soonest->expiry_date->toDateString(), 'days_left' => $soonest->daysLeft($today)] : null,
            'next_service' => $last && ($last->next_due_date || $kmLeft !== null) ? [
                'date' => $last->next_due_date?->toDateString(), 'days_left' => $serviceDays, 'km' => $last->next_due_km === null ? null : (float) $last->next_due_km, 'km_left' => $kmLeft,
                'due' => ($serviceDays !== null && $serviceDays <= 7) || ($kmLeft !== null && $kmLeft <= 500),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function entry(string $type, Model $e): array
    {
        $base = ['id' => $e->id, 'type' => $type, 'created_by' => $e->creator?->name, 'created_by_id' => $e->created_by];

        return $base + match ($type) {
            'trip' => ['date' => $e->trip_date->toDateString(), 'from_location' => $e->from_location, 'to_location' => $e->to_location,
                'start_time' => $e->start_time ? substr($e->start_time, 0, 5) : null, 'end_time' => $e->end_time ? substr($e->end_time, 0, 5) : null,
                'start_odometer' => (float) $e->start_odometer, 'end_odometer' => (float) $e->end_odometer, 'distance_km' => (float) $e->distance_km, 'purpose' => $e->purpose],
            'fuel' => ['date' => $e->fill_date->toDateString(), 'odometer_reading' => (float) $e->odometer_reading, 'liters' => (float) $e->liters,
                'cost' => $e->cost === null ? null : (float) $e->cost, 'fuel_station' => $e->fuel_station],
            default => ['date' => $e->service_date->toDateString(), 'service_type' => $e->type, 'description' => $e->description, 'cost' => $e->cost === null ? null : (float) $e->cost,
                'odometer_reading' => $e->odometer_reading === null ? null : (float) $e->odometer_reading, 'next_due_date' => $e->next_due_date?->toDateString(),
                'next_due_km' => $e->next_due_km === null ? null : (float) $e->next_due_km, 'vendor' => $e->vendor],
        };
    }

    private function today(): CarbonImmutable
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->startOfDay();
    }
}
