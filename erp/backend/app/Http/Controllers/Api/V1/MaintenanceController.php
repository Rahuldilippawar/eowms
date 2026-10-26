<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Department;
use App\Models\FinancePayment;
use App\Models\MaintenanceLocation;
use App\Models\MaintenanceLog;
use App\Services\AttendanceRules;
use App\Services\Finance\BudgetLedger;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The maintenance register (the old app's देखभाल नोंदवही): buildings and
 * locations shared by the organization, and the work departments with a
 * register log against them. A log's cost is a department spend; payments
 * against it use the finance payment ledger.
 */
class MaintenanceController extends Controller
{
    public function __construct(private readonly BudgetLedger $ledger) {}

    public function buildings(): JsonResponse
    {
        return response()->json(['data' => Building::query()->withCount('locations')->orderBy('name')->get()
            ->map(fn (Building $b) => ['id' => $b->id, 'name' => $b->name, 'institution_id' => $b->institution_id, 'locations_count' => $b->locations_count])]);
    }

    public function saveBuilding(Request $request, ?Building $building = null): JsonResponse
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('buildings')->where('organization_id', $orgId)->ignore($building?->id)],
            'institution_id' => ['nullable', 'integer', Rule::exists('institutions', 'id')->where('organization_id', $orgId)],
        ]);
        $before = $building?->getAttributes();
        $building = $building ? tap($building)->update($data) : Building::create($data + ['created_by' => $request->user()->id]);
        $before === null ? AuditLogger::record('maintenance', 'building_create', $building, null, AuditLogger::snapshot($building))
            : AuditLogger::recordChanges('maintenance', 'building_update', $building, $before);

        return response()->json(['data' => ['id' => $building->id, 'name' => $building->name, 'institution_id' => $building->institution_id]], $before === null ? 201 : 200);
    }

    public function locations(Request $request): JsonResponse
    {
        $f = $request->validate([
            'building_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);
        $since = $this->today()->subDays(30)->toDateString();
        $logs = $this->scopedLogs('maintenance.view');
        $rows = MaintenanceLocation::query()->with('building:id,name')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->when($f['building_id'] ?? null, fn ($q, $id) => $q->where('building_id', $id))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('location_code', 'like', $like));
            })
            ->withCount(['logs as total_logs' => fn ($q) => $q->whereIn('id', (clone $logs)->select('id'))])
            ->withCount(['logs as recent_issues' => fn ($q) => $q->whereIn('id', (clone $logs)->select('id'))->whereIn('work_type', ['repaired', 'replaced'])->where('work_date', '>=', $since)])
            ->withMax(['logs as last_work_date' => fn ($q) => $q->whereIn('id', (clone $logs)->select('id'))], 'work_date')
            ->orderBy('building_id')->orderBy('floor')->orderBy('name')->limit(2000)->get();
        $ctx = AccessContext::current();

        return response()->json([
            'data' => $rows->map(fn (MaintenanceLocation $l) => $this->presentLocation($l) + [
                'total_logs' => $l->total_logs, 'recent_issues' => $l->recent_issues, 'last_work_date' => $l->last_work_date ? substr($l->last_work_date, 0, 10) : null,
            ])->values(),
            'meta' => [
                'can_manage' => $ctx->can('maintenance.manage'),
                'can_log' => $ctx->can('maintenance.log'),
                'can_export' => $ctx->can('maintenance.export'),
                'work_types' => MaintenanceLog::WORK_TYPES,
                'log_departments' => $this->logDepartments()->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])->values(),
            ],
        ]);
    }

    public function saveLocation(Request $request, ?MaintenanceLocation $location = null): JsonResponse
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location_code' => ['required', 'string', 'max:50', Rule::unique('maintenance_locations')->where('organization_id', $orgId)->ignore($location?->id)],
            'building_id' => ['nullable', 'integer', Rule::exists('buildings', 'id')->where('organization_id', $orgId)],
            'institution_id' => ['nullable', 'integer', Rule::exists('institutions', 'id')->where('organization_id', $orgId)],
            'floor' => ['nullable', 'integer', 'min:-5', 'max:200'],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_residential' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['floor'] ??= 0;
        if (! empty($data['building_id']) && empty($data['institution_id'])) {
            $data['institution_id'] = Building::query()->whereKey($data['building_id'])->value('institution_id');
        }
        $before = $location?->getAttributes();
        $location = $location ? tap($location)->update($data) : MaintenanceLocation::create($data + ['created_by' => $request->user()->id]);
        $before === null ? AuditLogger::record('maintenance', 'location_create', $location, null, AuditLogger::snapshot($location))
            : AuditLogger::recordChanges('maintenance', 'location_update', $location, $before);

        return response()->json(['data' => $this->presentLocation($location->load('building:id,name'))], $before === null ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function presentLocation(MaintenanceLocation $location): array
    {
        return [
            'id' => $location->id, 'name' => $location->name, 'location_code' => $location->location_code, 'floor' => $location->floor,
            'capacity' => $location->capacity, 'is_residential' => $location->is_residential, 'is_active' => $location->is_active,
            'institution_id' => $location->institution_id,
            'building' => $location->building ? ['id' => $location->building->id, 'name' => $location->building->name] : null,
        ];
    }

    /** The location's work history (the old location-history page). */
    public function showLocation(MaintenanceLocation $location): JsonResponse
    {
        $logs = $this->scopedLogs('maintenance.view')->where('location_id', $location->id)->with(['department:id,name', 'performer:id,name'])
            ->withSum('payments as paid_amount', 'amount')->orderByDesc('work_date')->orderByDesc('id')->limit(500)->get();

        return response()->json(['data' => $this->presentLocation($location->load('building:id,name')) + [
            'total_cost' => round((float) $logs->sum('cost'), 2),
            'logs' => $logs->map(fn (MaintenanceLog $l) => $this->log($l))->values(),
        ]]);
    }

    public function logs(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'location_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'work_type' => ['nullable', Rule::in(MaintenanceLog::WORK_TYPES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $query = $this->scopedLogs('maintenance.view')->with(['location.building:id,name', 'department:id,name', 'performer:id,name'])
            ->withSum('payments as paid_amount', 'amount')
            ->when($f['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['work_type'] ?? null, fn ($q, $t) => $q->where('work_type', $t))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('work_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('work_date', '<=', $d))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('item_description', 'like', '%'.addcslashes($s, '%_\\').'%'))
            ->orderByDesc('work_date')->orderByDesc('id');

        if (($f['format'] ?? null) === 'csv') {
            abort_unless(AccessContext::current()->can('maintenance.export'), 403, __('auth.forbidden'));
            $rows = $query->limit(20000)->get();
            AuditLogger::record('maintenance', 'export', null, null, ['rows' => $rows->count()]);

            return Csv::download('maintenance-log.csv', ['date', 'building', 'location', 'code', 'department', 'item', 'work_type', 'cost', 'paid', 'done_by', 'remarks'],
                $rows->map(fn (MaintenanceLog $l) => [
                    $l->work_date->toDateString(), $l->location?->building?->name, $l->location?->name, $l->location?->location_code, $l->department?->name,
                    $l->item_description, $l->work_type, $l->cost === null ? '' : (float) $l->cost, round((float) $l->paid_amount, 2), $l->performer?->name, $l->remarks,
                ]));
        }
        $page = $query->paginate(25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (MaintenanceLog $l) => $this->log($l))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    public function showLog(MaintenanceLog $log): JsonResponse
    {
        $log = $this->scopedLogs('maintenance.view')->with(['location.building:id,name', 'department:id,name', 'performer:id,name', 'payments.recorder:id,name'])
            ->withSum('payments as paid_amount', 'amount')->findOrFail($log->id);

        return response()->json(['data' => $this->log($log) + [
            'payments' => $log->payments->map(fn (FinancePayment $p) => [
                'id' => $p->id, 'amount' => (float) $p->amount, 'payment_date' => $p->payment_date->toDateString(), 'payment_type' => $p->payment_type,
                'payment_mode' => $p->payment_mode, 'remarks' => $p->remarks, 'recorded_by' => $p->recorder?->name,
            ])->values(),
            'can_manage' => ScopeDepartments::reaches(AccessContext::current()->scope('maintenance.manage'), $log->department_id),
        ]]);
    }

    public function storeLog(Request $request): JsonResponse
    {
        $data = $this->validatedLog($request, 'maintenance.log');
        $log = DB::transaction(function () use ($data, $request) {
            $log = MaintenanceLog::create($data + ['performed_by' => $request->user()->id]);
            $this->charge($log, $request->user()->id);
            AuditLogger::record('maintenance', 'log_create', $log, null, AuditLogger::snapshot($log));

            return $log;
        });

        return $this->showLog($log)->setStatusCode(201);
    }

    public function updateLog(Request $request, MaintenanceLog $log): JsonResponse
    {
        $log = $this->managedLog($log);
        $data = $this->validatedLog($request, 'maintenance.manage');
        $paid = (float) $log->payments()->sum('amount');
        if ($paid > (float) ($data['cost'] ?? 0) + 0.001) {
            throw ValidationException::withMessages(['cost' => [__('finance.total_below_paid', ['paid' => number_format($paid, 2)])]]);
        }
        DB::transaction(function () use ($log, $data, $request) {
            $before = $log->getAttributes();
            $log->fill($data)->save();
            $this->charge($log, $request->user()->id);
            FinancePayment::query()->where('source_type', 'maintenance_log')->where('source_id', $log->id)->update(['department_id' => $log->department_id]);
            AuditLogger::recordChanges('maintenance', 'log_update', $log, $before);
        });

        return $this->showLog($log);
    }

    public function destroyLog(MaintenanceLog $log): JsonResponse
    {
        $log = $this->managedLog($log);
        if ($log->payments()->exists()) {
            throw ValidationException::withMessages(['log' => [__('finance.has_payments')]]);
        }
        DB::transaction(function () use ($log) {
            AuditLogger::record('maintenance', 'log_delete', $log, AuditLogger::snapshot($log));
            $this->ledger->reverse('maintenance_log', $log->id);
            $log->delete();
        });

        return response()->json(['data' => ['id' => $log->id, 'deleted' => true]]);
    }

    public function pay(Request $request, MaintenanceLog $log): JsonResponse
    {
        $log = $this->managedLog($log);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'payment_type' => ['required', Rule::in(FinancePayment::TYPES)],
            'payment_mode' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($log, $data, $request) {
            $locked = MaintenanceLog::query()->whereKey($log->id)->lockForUpdate()->firstOrFail();
            $due = round((float) $locked->cost - (float) $locked->payments()->sum('amount'), 2);
            if ((float) $data['amount'] > $due + 0.001) {
                throw ValidationException::withMessages(['amount' => [__('finance.more_than_due', ['due' => number_format(max($due, 0), 2)])]]);
            }
            $payment = FinancePayment::create($data + [
                'department_id' => $locked->department_id, 'source_type' => 'maintenance_log', 'source_id' => $locked->id, 'recorded_by' => $request->user()->id,
            ]);
            AuditLogger::record('maintenance', 'payment', $locked, null, ['payment' => $payment->id, 'amount' => $payment->amount]);
        });

        return $this->showLog($log);
    }

    /** Which departments keep a register (the old settings page). */
    public function departments(): JsonResponse
    {
        return response()->json(['data' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'has_maintenance_register'])
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code, 'has_maintenance_register' => $d->has_maintenance_register])]);
    }

    public function setDepartment(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate(['has_maintenance_register' => ['required', 'boolean']]);
        abort_unless(ScopeDepartments::reaches(AccessContext::current()->scope('maintenance.manage'), $department->id), 403, __('auth.forbidden'));
        $before = $department->getAttributes();
        $department->forceFill($data)->save();
        AuditLogger::recordChanges('maintenance', 'register_toggle', $department, $before);

        return response()->json(['data' => ['id' => $department->id, 'has_maintenance_register' => $department->has_maintenance_register]]);
    }

    /** Totals for a period: by department, by location and by kind of work. */
    public function report(Request $request): JsonResponse
    {
        $today = $this->today();
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $f['from'] ?? BudgetLedger::fyOf($today->toDateString()).'-04-01';
        $to = $f['to'] ?? $today->toDateString();
        $base = fn () => $this->scopedLogs('maintenance.view')->whereBetween('maintenance_logs.work_date', [$from, $to]);
        $paid = DB::table('finance_payments')->where('source_type', 'maintenance_log')->selectRaw('source_id, sum(amount) as paid')->groupBy('source_id');

        $byDepartment = $base()->join('departments', 'departments.id', '=', 'maintenance_logs.department_id')
            ->leftJoinSub($paid, 'p', 'p.source_id', '=', 'maintenance_logs.id')
            ->groupBy('departments.id', 'departments.name')
            ->selectRaw('departments.id, departments.name, count(*) as entries, coalesce(sum(maintenance_logs.cost), 0) as cost, coalesce(sum(p.paid), 0) as paid')
            ->orderByDesc('cost')->get();
        $byLocation = $base()->join('maintenance_locations as l', 'l.id', '=', 'maintenance_logs.location_id')
            ->groupBy('l.id', 'l.name', 'l.location_code')
            ->selectRaw('l.id, l.name, l.location_code, count(*) as entries, coalesce(sum(maintenance_logs.cost), 0) as cost')
            ->orderByDesc('entries')->limit(20)->get();
        $byType = $base()->groupBy('work_type')->selectRaw('work_type, count(*) as entries, coalesce(sum(cost), 0) as cost')->get();

        $num = fn ($rows, array $keys) => $rows->map(fn ($r) => collect((array) $r->getAttributes())->map(fn ($v, $k) => in_array($k, $keys, true) ? round((float) $v, 2) : $v)->all())->values();

        return response()->json(['data' => [
            'from' => $from, 'to' => $to,
            'by_department' => $num($byDepartment, ['entries', 'cost', 'paid']),
            'by_location' => $num($byLocation, ['entries', 'cost']),
            'by_work_type' => $num($byType, ['entries', 'cost']),
        ]]);
    }

    /** @return Builder<MaintenanceLog> */
    private function scopedLogs(string $permission): Builder
    {
        $ids = ScopeDepartments::ids(AccessContext::current()->scope($permission));

        return MaintenanceLog::query()->when($ids !== null, fn ($q) => $q->whereIn('maintenance_logs.department_id', $ids ?: [0]));
    }

    private function managedLog(MaintenanceLog $log): MaintenanceLog
    {
        return $this->scopedLogs('maintenance.manage')->findOrFail($log->id);
    }

    /** Departments that keep a register and are in the viewer's log scope. */
    private function logDepartments()
    {
        $ctx = AccessContext::current();
        $ids = ScopeDepartments::ids($ctx->can('maintenance.log') ? $ctx->scope('maintenance.log') : null);

        return Department::query()->where('is_active', true)->where('has_maintenance_register', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('name')->get(['id', 'name']);
    }

    /** @return array<string, mixed> */
    private function validatedLog(Request $request, string $permission): array
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'location_id' => ['required', 'integer', Rule::exists('maintenance_locations', 'id')->where('organization_id', $orgId)->where('is_active', true)],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)->where('has_maintenance_register', true)],
            'item_description' => ['required', 'string', 'max:255'],
            'work_type' => ['required', Rule::in(MaintenanceLog::WORK_TYPES)],
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()->toDateString()],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! ScopeDepartments::reaches(AccessContext::current()->scope($permission), (int) $data['department_id'])) {
            throw ValidationException::withMessages(['department_id' => [__('access.outside_scope')]]);
        }

        return $data;
    }

    private function charge(MaintenanceLog $log, int $userId): void
    {
        $location = MaintenanceLocation::query()->find($log->location_id);
        $this->ledger->record(Department::query()->findOrFail($log->department_id), (float) $log->cost, 'maintenance', 'maintenance_log', $log->id,
            "{$log->item_description} — {$location?->name}", $log->work_date->toDateString(), $userId);
    }

    /** @return array<string, mixed> */
    private function log(MaintenanceLog $l): array
    {
        $cost = $l->cost === null ? null : (float) $l->cost;
        $paid = round((float) ($l->paid_amount ?? 0), 2);

        return [
            'id' => $l->id,
            'location' => $l->relationLoaded('location') && $l->location ? ['id' => $l->location->id, 'name' => $l->location->name, 'location_code' => $l->location->location_code,
                'building' => $l->location->building?->name] : ['id' => $l->location_id],
            'department' => $l->department ? ['id' => $l->department->id, 'name' => $l->department->name] : null,
            'item_description' => $l->item_description,
            'work_type' => $l->work_type,
            'work_date' => $l->work_date->toDateString(),
            'cost' => $cost,
            'paid_amount' => $paid,
            'due_amount' => $cost === null ? 0 : round($cost - $paid, 2),
            'payment_status' => ! $cost ? null : ($paid <= 0 ? 'pending' : ($paid + 0.001 >= $cost ? 'paid' : 'partial')),
            'remarks' => $l->remarks,
            'performed_by' => $l->performer?->name,
        ];
    }

    private function today()
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->startOfDay();
    }
}
