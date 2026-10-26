<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelAlumnus;
use App\Models\HostelFee;
use App\Models\HostelFeePayment;
use App\Models\HostelHealthIncident;
use App\Models\HostelHealthProfile;
use App\Models\HostelLeave;
use App\Models\HostelResident;
use App\Models\HostelRoom;
use App\Services\AttendanceRules;
use App\Services\Hostels\HostelAccess;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use App\Support\Forms\Dates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Residents of a hostel and everything kept about them: bed, out-passes,
 * health, fees and, when they leave, the alumni record.
 */
class HostelResidentController extends Controller
{
    public const IMPORT_COLUMNS = ['name', 'school_name', 'class_grade', 'district', 'gender', 'admission_date', 'date_of_birth', 'guardian_name', 'guardian_phone'];

    public function __construct(private readonly HostelAccess $access) {}

    public function index(Request $request, Hostel $hostel): JsonResponse|StreamedResponse
    {
        $hostel = $this->access->cared($hostel->id);
        $f = $request->validate(['status' => ['nullable', Rule::in(['active', 'left', 'out'])], 'search' => ['nullable', 'string', 'max:100'], 'format' => ['nullable', Rule::in(['csv'])]]);
        $status = $f['status'] ?? 'active';
        $rows = $hostel->residents()
            ->with(['allocations' => fn ($q) => $q->whereNull('vacated_on')->with('room:id,room_number'), 'leaves' => fn ($q) => $q->whereNull('actual_return_date')])
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'left', fn ($q) => $q->where('is_active', false)->with('alumnus'))
            ->when($status === 'out', fn ($q) => $q->where('is_active', true)->whereHas('leaves', fn ($l) => $l->whereNull('actual_return_date')))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('school_name', 'like', $like)->orWhere('district', 'like', $like));
            })
            ->orderBy('name')->limit(2000)->get();

        if (($f['format'] ?? null) === 'csv') {
            abort_unless(AccessContext::current()->can('hostels.export'), 403, __('auth.forbidden'));
            AuditLogger::record('hostels', 'residents_export', $hostel, null, ['rows' => $rows->count()]);

            return Csv::download('hostel-residents.csv', ['name', 'school', 'class', 'district', 'gender', 'admission_date', 'room', 'bed', 'guardian', 'guardian_phone', 'active'],
                $rows->map(fn (HostelResident $r) => [
                    $r->name, $r->school_name, $r->class_grade, $r->district, $r->gender, $r->admission_date?->toDateString(),
                    $r->allocations->first()?->room?->room_number, $r->allocations->first()?->bed_label, $r->guardian_name, $r->guardian_phone, $r->is_active ? 'yes' : 'no',
                ]));
        }

        return response()->json(['data' => $rows->map(fn (HostelResident $r) => $this->present($r))->values()]);
    }

    public function show(HostelResident $resident): JsonResponse
    {
        $r = $this->access->resident($resident->id);
        $r->load([
            'allocations' => fn ($q) => $q->with('room:id,room_number')->orderByDesc('allocated_on')->orderByDesc('id'),
            'leaves' => fn ($q) => $q->with('approver:id,name')->orderByDesc('leave_date')->orderByDesc('id'),
            'fees' => fn ($q) => $q->with('payments.receiver:id,name')->orderByDesc('id'),
            'incidents' => fn ($q) => $q->with('reporter:id,name')->orderByDesc('incident_date'),
            'health', 'alumnus',
        ]);
        $hostel = Hostel::query()->findOrFail($r->hostel_id);
        $today = $this->today();
        $attendance = DB::table('hostel_attendance')->where('resident_id', $r->id)->where('attendance_date', '>=', now()->subDays(30)->toDateString())
            ->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');

        return response()->json(['data' => $this->present($r) + [
            'student_id' => $r->student_id,
            'date_of_birth' => $r->date_of_birth?->toDateString(),
            'hostel' => ['id' => $hostel->id, 'name' => $hostel->name],
            'rooms' => $hostel->rooms()->where('is_active', true)->withCount(['allocations as occupied' => fn ($q) => $q->whereNull('vacated_on')])->get()
                ->map(fn (HostelRoom $room) => ['id' => $room->id, 'room_number' => $room->room_number, 'capacity' => $room->capacity, 'occupied' => $room->occupied])->values(),
            'allocations' => $r->allocations->map(fn (HostelAllocation $a) => [
                'id' => $a->id, 'room' => $a->room?->room_number, 'bed_label' => $a->bed_label, 'allocated_on' => $a->allocated_on->toDateString(), 'vacated_on' => $a->vacated_on?->toDateString(),
            ])->values(),
            'leaves' => $r->leaves->map(fn (HostelLeave $l) => [
                'id' => $l->id, 'leave_date' => $l->leave_date->toDateString(), 'expected_return_date' => $l->expected_return_date?->toDateString(),
                'actual_return_date' => $l->actual_return_date?->toDateString(), 'reason' => $l->reason, 'escort_name' => $l->escort_name, 'approved_by' => $l->approver?->name,
                'overdue' => $l->actual_return_date === null && $l->expected_return_date !== null && $l->expected_return_date->toDateString() < $today,
            ])->values(),
            'health' => $r->health?->only(['blood_group', 'allergies', 'regular_medication', 'emergency_contact_name', 'emergency_contact_phone', 'medical_notes']),
            'incidents' => $r->incidents->map(fn (HostelHealthIncident $i) => [
                'id' => $i->id, 'incident_date' => $i->incident_date->toDateString(), 'description' => $i->description, 'action_taken' => $i->action_taken, 'reported_by' => $i->reporter?->name,
            ])->values(),
            'fees' => $r->fees->map(function (HostelFee $fee) {
                $paid = round((float) $fee->payments->sum('amount'), 2);

                return [
                    'id' => $fee->id, 'fee_period' => $fee->fee_period, 'amount_due' => (float) $fee->amount_due, 'due_date' => $fee->due_date?->toDateString(),
                    'paid' => $paid, 'balance' => round((float) $fee->amount_due - $paid, 2), 'status' => $paid <= 0 ? 'pending' : ($paid + 0.001 >= (float) $fee->amount_due ? 'paid' : 'partial'),
                    'payments' => $fee->payments->map(fn (HostelFeePayment $p) => [
                        'id' => $p->id, 'amount' => (float) $p->amount, 'payment_mode' => $p->payment_mode, 'paid_on' => $p->paid_on->toDateString(), 'remarks' => $p->remarks, 'received_by' => $p->receiver?->name,
                    ])->values(),
                ];
            })->values(),
            'alumnus' => $r->alumnus?->only(['departure_date', 'departure_reason', 'current_status', 'contact_number', 'notes']),
            'attendance_30_days' => ['present' => (int) ($attendance['present'] ?? 0), 'absent' => (int) ($attendance['absent'] ?? 0), 'on_leave' => (int) ($attendance['on_leave'] ?? 0)],
        ]]);
    }

    public function store(Request $request, Hostel $hostel): JsonResponse
    {
        $hostel = $this->access->cared($hostel->id);
        $resident = HostelResident::create($this->validated($request) + ['hostel_id' => $hostel->id, 'is_active' => true]);
        AuditLogger::record('hostels', 'resident_create', $resident, null, ['hostel' => $hostel->id, 'name' => $resident->name]);

        return $this->show($resident)->setStatusCode(201);
    }

    public function update(Request $request, HostelResident $resident): JsonResponse
    {
        $r = $this->active($resident);
        $before = $r->getAttributes();
        $r->fill($this->validated($request))->save();
        AuditLogger::recordChanges('hostels', 'resident_update', $r, $before);

        return $this->show($r);
    }

    /** A bed in a room of the same hostel; a previous bed is vacated the same day. */
    public function allocate(Request $request, HostelResident $resident): JsonResponse
    {
        $r = $this->active($resident);
        $data = $request->validate([
            'room_id' => ['required', 'integer', Rule::exists('hostel_rooms', 'id')->where('hostel_id', $r->hostel_id)->where('is_active', true)],
            'bed_label' => ['nullable', 'string', 'max:20'],
        ]);
        DB::transaction(function () use ($r, $data, $request) {
            $room = HostelRoom::query()->whereKey($data['room_id'])->lockForUpdate()->firstOrFail();
            $occupied = $room->allocations()->whereNull('vacated_on')->where('resident_id', '!=', $r->id)->count();
            if ($occupied >= $room->capacity) {
                throw ValidationException::withMessages(['room_id' => [__('hostels.room_full', ['room' => $room->room_number])]]);
            }
            if (! empty($data['bed_label']) && $room->allocations()->whereNull('vacated_on')->where('resident_id', '!=', $r->id)->where('bed_label', $data['bed_label'])->exists()) {
                throw ValidationException::withMessages(['bed_label' => [__('hostels.bed_taken')]]);
            }
            HostelAllocation::query()->where('resident_id', $r->id)->whereNull('vacated_on')->update(['vacated_on' => $this->today()]);
            HostelAllocation::create(['room_id' => $room->id, 'resident_id' => $r->id, 'bed_label' => $data['bed_label'] ?? null, 'allocated_on' => $this->today(), 'created_by' => $request->user()->id]);
            AuditLogger::record('hostels', 'allocate', $r, null, ['room' => $room->room_number, 'bed' => $data['bed_label'] ?? null]);
        });

        return $this->show($r);
    }

    public function vacate(HostelResident $resident): JsonResponse
    {
        $r = $this->access->resident($resident->id);
        HostelAllocation::query()->where('resident_id', $r->id)->whereNull('vacated_on')->update(['vacated_on' => $this->today()]);
        AuditLogger::record('hostels', 'vacate', $r);

        return $this->show($r);
    }

    /** An out-pass: one open at a time; marking the return closes it. */
    public function goOut(Request $request, HostelResident $resident): JsonResponse
    {
        $r = $this->active($resident);
        $data = $request->validate([
            'leave_date' => ['required', 'date_format:Y-m-d'],
            'expected_return_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:leave_date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'escort_name' => ['nullable', 'string', 'max:255'],
        ]);
        if ($r->leaves()->whereNull('actual_return_date')->exists()) {
            throw ValidationException::withMessages(['leave_date' => [__('hostels.already_out')]]);
        }
        $leave = HostelLeave::create($data + ['resident_id' => $r->id, 'approved_by' => $request->user()->id]);
        AuditLogger::record('hostels', 'out', $r, null, ['leave' => $leave->id]);

        return $this->show($r);
    }

    public function returned(Request $request, HostelResident $resident): JsonResponse
    {
        $r = $this->access->resident($resident->id);
        $data = $request->validate(['actual_return_date' => ['nullable', 'date_format:Y-m-d']]);
        $leave = $r->leaves()->whereNull('actual_return_date')->first();
        if ($leave === null) {
            throw ValidationException::withMessages(['actual_return_date' => [__('hostels.not_out')]]);
        }
        $date = $data['actual_return_date'] ?? $this->today();
        if ($date < $leave->leave_date->toDateString()) {
            throw ValidationException::withMessages(['actual_return_date' => [__('validation.after_or_equal', ['attribute' => 'return date', 'date' => $leave->leave_date->toDateString()])]]);
        }
        $leave->forceFill(['actual_return_date' => $date])->save();
        AuditLogger::record('hostels', 'returned', $r, null, ['leave' => $leave->id]);

        return $this->show($r);
    }

    public function saveHealth(Request $request, HostelResident $resident): JsonResponse
    {
        $r = $this->access->resident($resident->id);
        $data = $request->validate([
            'blood_group' => ['nullable', 'string', 'max:10'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'regular_medication' => ['nullable', 'string', 'max:2000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'medical_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        HostelHealthProfile::query()->updateOrCreate(['resident_id' => $r->id], $data);
        // Health details are sensitive: the audit says that they changed, not what they are.
        AuditLogger::record('hostels', 'health_update', $r);

        return $this->show($r);
    }

    public function addIncident(Request $request, HostelResident $resident): JsonResponse
    {
        $r = $this->access->resident($resident->id);
        $data = $request->validate([
            'incident_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'description' => ['required', 'string', 'max:5000'],
            'action_taken' => ['nullable', 'string', 'max:5000'],
        ]);
        HostelHealthIncident::create($data + ['resident_id' => $r->id, 'reported_by' => $request->user()->id]);
        AuditLogger::record('hostels', 'health_incident', $r);

        return $this->show($r);
    }

    public function addFee(Request $request, HostelResident $resident): JsonResponse
    {
        $r = $this->access->resident($resident->id);
        $data = $request->validate([
            'fee_period' => ['required', 'string', 'max:50'],
            'amount_due' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $fee = HostelFee::create($data + ['resident_id' => $r->id, 'created_by' => $request->user()->id]);
        AuditLogger::record('hostels', 'fee', $r, null, ['fee' => $fee->id, 'amount' => $fee->amount_due]);

        return $this->show($r);
    }

    public function payFee(Request $request, HostelFee $fee): JsonResponse
    {
        $r = $this->access->resident($fee->resident_id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'payment_mode' => ['required', Rule::in(['cash', 'online', 'cheque', 'other'])],
            'paid_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($fee, $data, $request, $r) {
            $locked = HostelFee::query()->whereKey($fee->id)->lockForUpdate()->firstOrFail();
            $balance = round((float) $locked->amount_due - (float) $locked->payments()->sum('amount'), 2);
            if ((float) $data['amount'] > $balance + 0.001) {
                throw ValidationException::withMessages(['amount' => [__('finance.more_than_due', ['due' => number_format(max($balance, 0), 2)])]]);
            }
            HostelFeePayment::create(['fee_id' => $locked->id, 'paid_on' => $data['paid_on'] ?? $this->today(), 'received_by' => $request->user()->id] + $data);
            AuditLogger::record('hostels', 'fee_payment', $r, null, ['fee' => $locked->id, 'amount' => $data['amount']]);
        });

        return $this->show($r);
    }

    /** Leaving the hostel: the bed is freed and the resident joins the alumni list. */
    public function depart(Request $request, HostelResident $resident): JsonResponse
    {
        $r = $this->active($resident);
        $data = $request->validate([
            'departure_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'departure_reason' => ['nullable', 'string', 'max:255'],
            'current_status' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($r, $data, $request) {
            HostelAlumnus::query()->updateOrCreate(['resident_id' => $r->id], $data + ['created_by' => $request->user()->id]);
            HostelAllocation::query()->where('resident_id', $r->id)->whereNull('vacated_on')->update(['vacated_on' => $data['departure_date']]);
            HostelLeave::query()->where('resident_id', $r->id)->whereNull('actual_return_date')->update(['actual_return_date' => $data['departure_date']]);
            $r->forceFill(['is_active' => false])->save();
            AuditLogger::record('hostels', 'depart', $r, null, ['date' => $data['departure_date']]);
        });

        return $this->show($r);
    }

    /** Keeping in touch with former residents (the old alumni page). */
    public function updateAlumnus(Request $request, HostelResident $resident): JsonResponse
    {
        $r = $this->access->resident($resident->id);
        abort_if($r->is_active || $r->alumnus === null, 404);
        $data = $request->validate([
            'current_status' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $r->alumnus->fill($data)->save();

        return $this->show($r);
    }

    public function importTemplate(): StreamedResponse
    {
        return Csv::download('hostel-residents-template.csv', self::IMPORT_COLUMNS, [
            ['Suraj Kamble', 'ZP School No. 2', '7th', 'Thane', 'male', '2026-06-15', '2013-03-20', 'Ramesh Kamble', '9876543210'],
        ]);
    }

    public function importPreview(Request $request, Hostel $hostel): JsonResponse
    {
        $this->access->cared($hostel->id);
        $checked = $this->checkImport($request);

        return response()->json(['data' => ['rows' => $checked, 'total' => count($checked), 'with_errors' => collect($checked)->filter(fn ($r) => $r['errors'] !== [])->count()]]);
    }

    public function import(Request $request, Hostel $hostel): JsonResponse
    {
        $hostel = $this->access->cared($hostel->id);
        $checked = $this->checkImport($request);
        $bad = collect($checked)->filter(fn ($r) => $r['errors'] !== [])->values();
        if ($bad->isNotEmpty()) {
            return response()->json(['message' => __('import.has_errors'), 'errors' => ['file' => [__('import.has_errors')]], 'rows' => $bad], 422);
        }
        DB::transaction(function () use ($checked, $hostel) {
            foreach ($checked as $row) {
                HostelResident::create($row['data'] + ['hostel_id' => $hostel->id, 'is_active' => true]);
            }
            AuditLogger::record('hostels', 'residents_import', $hostel, null, ['rows' => count($checked)]);
        });

        return response()->json(['data' => ['imported' => count($checked)]], 201);
    }

    /** @return list<array{line: int, data: array<string, mixed>, errors: array<string, string>}> */
    private function checkImport(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);
        $rows = CsvReader::rows($request->file('file'), ['name'], 1000);
        $genders = ['male' => 'male', 'female' => 'female', 'm' => 'male', 'f' => 'female', 'boy' => 'male', 'girl' => 'female', 'मुलगा' => 'male', 'मुलगी' => 'female'];
        $result = [];
        foreach ($rows as $i => $row) {
            $errors = [];
            $data = [];
            foreach (['name' => 255, 'school_name' => 255, 'class_grade' => 50, 'district' => 100, 'guardian_name' => 255, 'guardian_phone' => 30] as $field => $max) {
                $value = trim((string) ($row[$field] ?? ''));
                $data[$field] = $value === '' ? null : $value;
                if (mb_strlen($value) > $max) {
                    $errors[$field] = __('validation.max.string', ['attribute' => $field, 'max' => $max]);
                }
            }
            if ($data['name'] === null) {
                $errors['name'] = __('validation.required', ['attribute' => 'name']);
            }
            $gender = mb_strtolower(trim((string) ($row['gender'] ?? '')));
            $data['gender'] = $gender === '' ? null : ($genders[$gender] ?? null);
            if ($gender !== '' && $data['gender'] === null) {
                $errors['gender'] = __('hostels.bad_gender');
            }
            foreach (['admission_date', 'date_of_birth'] as $field) {
                $data[$field] = ($row[$field] ?? '') === '' ? null : Dates::parse($row[$field]);
                if (($row[$field] ?? '') !== '' && $data[$field] === null) {
                    $errors[$field] = __('import.bad_date');
                }
            }
            $result[] = ['line' => $i + 2, 'data' => $data, 'errors' => $errors];
        }

        return $result;
    }

    private function active(HostelResident $resident): HostelResident
    {
        $r = $this->access->resident($resident->id);
        if (! $r->is_active) {
            throw ValidationException::withMessages(['resident' => [__('hostels.resident_left')]]);
        }

        return $r;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'school_name' => ['nullable', 'string', 'max:255'],
            'class_grade' => ['nullable', 'string', 'max:50'],
            'district' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'admission_date' => ['nullable', 'date_format:Y-m-d'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(HostelResident $r): array
    {
        $bed = $r->relationLoaded('allocations') ? $r->allocations->firstWhere('vacated_on', null) : null;
        $out = $r->relationLoaded('leaves') ? $r->leaves->firstWhere('actual_return_date', null) : null;

        return [
            'id' => $r->id, 'name' => $r->name, 'school_name' => $r->school_name, 'class_grade' => $r->class_grade, 'district' => $r->district,
            'gender' => $r->gender, 'admission_date' => $r->admission_date?->toDateString(), 'guardian_name' => $r->guardian_name, 'guardian_phone' => $r->guardian_phone,
            'is_active' => $r->is_active,
            'room' => $bed ? ['room_id' => $bed->room_id, 'room_number' => $bed->room?->room_number, 'bed_label' => $bed->bed_label] : null,
            'out' => $out ? ['since' => $out->leave_date->toDateString(), 'expected_return_date' => $out->expected_return_date?->toDateString(),
                'overdue' => $out->expected_return_date !== null && $out->expected_return_date->toDateString() < $this->today()] : null,
        ];
    }

    private function today(): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
    }
}
