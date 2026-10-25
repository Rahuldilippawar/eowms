<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceRules;
use App\Services\AttendanceService;
use App\Services\Notifier;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Queries\PeopleQueries;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    /** Every active employee in scope for one day, with that day's record (or none). */
    public function day(Request $request): JsonResponse
    {
        $tz = AttendanceRules::for(AccessContext::current()->organization())->timezone;
        $f = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $date = $f['date'] ?? CarbonImmutable::now($tz)->toDateString();

        $employees = PeopleQueries::employees('attendance.view')->where('is_active', true)->with('department')
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('employee_code', 'like', "%{$s}%")))
            ->orderBy('name')->paginate($f['per_page'] ?? 50)->withQueryString();

        $records = AttendanceRecord::query()->where('work_date', $date)->whereIn('employee_id', $employees->pluck('id'))->get()->keyBy('employee_id');

        return response()->json([
            'date' => $date,
            'data' => $employees->getCollection()->map(fn (Employee $e) => [
                'employee' => ['id' => $e->id, 'name' => $e->name, 'employee_code' => $e->employee_code, 'department' => $e->department?->name],
                'record' => ($r = $records->get($e->id)) ? [
                    'id' => $r->id, 'status' => $r->status->value, 'source' => $r->source, 'remarks' => $r->remarks,
                    'check_in_at' => $r->check_in_at?->toIso8601String(), 'check_out_at' => $r->check_out_at?->toIso8601String(),
                ] : null,
            ])->values(),
            'meta' => ['current_page' => $employees->currentPage(), 'last_page' => $employees->lastPage(), 'per_page' => $employees->perPage(), 'total' => $employees->total(), 'from' => $employees->firstItem(), 'to' => $employees->lastItem()],
        ]);
    }

    /** Manual marking / editing by an authorized person (audited). */
    public function mark(Request $request, AttendanceService $service): JsonResponse
    {
        $tz = AttendanceRules::for(AccessContext::current()->organization())->timezone;
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now($tz)->toDateString()],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $exists = AttendanceRecord::query()->where(['employee_id' => $data['employee_id'], 'work_date' => $data['date']])->exists();
        $permission = $exists ? 'attendance.edit' : 'attendance.create';
        abort_unless(AccessContext::current()->can($permission), 403, __('auth.forbidden'));

        $employee = PeopleQueries::employees($permission)->whereKey($data['employee_id'])->first();
        abort_if($employee === null, 404);

        $record = $service->mark($employee, $data['date'], AttendanceStatus::from($data['status']), $data['check_in'] ?? null, $data['check_out'] ?? null, $data['remarks'] ?? null, 'manual', $request->user()->id);

        return response()->json(['data' => ['id' => $record->id, 'status' => $record->status->value]]);
    }

    public function corrections(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]]);
        $rows = PeopleQueries::corrections()->with('employee')
            ->where('status', $f['status'] ?? 'pending')
            // Nobody decides their own correction.
            ->whereNotIn('employee_id', Employee::query()->where('user_id', $request->user()->id)->select('id'))
            ->orderBy('work_date')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (AttendanceCorrection $c) => [
            'id' => $c->id,
            'employee' => ['id' => $c->employee->id, 'name' => $c->employee->name, 'employee_code' => $c->employee->employee_code],
            'work_date' => $c->work_date->toDateString(),
            'requested_status' => $c->requested_status->value,
            'requested_check_in' => $c->requested_check_in ? substr($c->requested_check_in, 0, 5) : null,
            'requested_check_out' => $c->requested_check_out ? substr($c->requested_check_out, 0, 5) : null,
            'reason' => $c->reason,
            'status' => $c->status,
            'decision_remarks' => $c->decision_remarks,
        ])]);
    }

    public function decideCorrection(Request $request, AttendanceCorrection $correction, AttendanceService $service): JsonResponse
    {
        abort_unless(PeopleQueries::corrections()->whereKey($correction->id)->exists(), 404);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:500']]);
        if ($correction->employee->user_id === $request->user()->id) {
            throw ValidationException::withMessages(['correction' => [__('attendance.not_self')]]);
        }

        DB::transaction(function () use ($correction, $data, $request, $service) {
            $locked = AttendanceCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['correction' => [__('attendance.already_decided')]]);
            }
            $before = $locked->getAttributes();
            $locked->forceFill([
                'status' => $data['approve'] ? 'approved' : 'rejected',
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
                'decision_remarks' => $data['remarks'] ?? null,
            ])->save();
            AuditLogger::recordChanges('attendance', $data['approve'] ? 'correction_approved' : 'correction_rejected', $locked, $before, $data['remarks'] ?? null);

            if ($data['approve']) {
                $service->mark($locked->employee, $locked->work_date->toDateString(), $locked->requested_status,
                    $locked->requested_check_in ? substr($locked->requested_check_in, 0, 5) : null,
                    $locked->requested_check_out ? substr($locked->requested_check_out, 0, 5) : null,
                    "correction #{$locked->id}: {$locked->reason}", 'correction', $request->user()->id);
            }
        });

        Notifier::employee($correction->employee, 'correction.decided', [
            'correction_id' => $correction->id, 'status' => $data['approve'] ? 'approved' : 'rejected',
            'work_date' => $correction->work_date->toDateString(), 'by' => $request->user()->name, 'note' => $data['remarks'] ?? null,
        ], '/my/attendance');

        return response()->json(['data' => ['id' => $correction->id, 'status' => $data['approve'] ? 'approved' : 'rejected']]);
    }

    /**
     * Counts per status for a date range, grouped by employee, department or
     * institution. `format=csv` (needs attendance.export) streams the same rows.
     */
    public function summary(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['required', Rule::in(['employee', 'department', 'institution'])],
            'department_id' => ['nullable', 'integer'],
            'institution_id' => ['nullable', 'integer'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);
        abort_if(CarbonImmutable::parse($f['from'])->diffInDays(CarbonImmutable::parse($f['to'])) > 366, 422, __('validation.max.numeric', ['attribute' => 'range', 'max' => 366]));
        $csv = ($f['format'] ?? 'json') === 'csv';
        if ($csv) {
            abort_unless(AccessContext::current()->can('attendance.export'), 403, __('auth.forbidden'));
        }

        [$keyCol, $table, $codeCol] = match ($f['group_by']) {
            'employee' => ['a.employee_id', 'employees', 'g.employee_code'],
            'department' => ['a.department_id', 'departments', 'g.code'],
            'institution' => ['a.institution_id', 'institutions', 'g.code'],
        };

        $query = DB::table('attendance_records as a')
            ->leftJoin("{$table} as g", 'g.id', '=', $keyCol)
            ->where('a.organization_id', AccessContext::current()->organizationId())
            ->whereIn('a.employee_id', PeopleQueries::employeeIds('attendance.view'))
            ->whereBetween('a.work_date', [$f['from'], $f['to']])
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('a.department_id', $id))
            ->when($f['institution_id'] ?? null, fn ($q, $id) => $q->where('a.institution_id', $id))
            ->select(DB::raw("{$keyCol} as key_id"), DB::raw('g.name as label'), DB::raw("{$codeCol} as code"))
            ->selectRaw("SUM(a.status = 'present') as present, SUM(a.status = 'late') as late, SUM(a.status = 'half_day') as half_day, SUM(a.status = 'absent') as absent, SUM(a.status = 'leave') as `leave`")
            ->groupBy($keyCol, 'g.name', $codeCol);
        $rows = $query->orderBy('g.name')->get()->map(fn ($r) => [
            'id' => $r->key_id, 'label' => $r->label, 'code' => $r->code,
            'present' => (int) $r->present, 'late' => (int) $r->late, 'half_day' => (int) $r->half_day, 'absent' => (int) $r->absent, 'leave' => (int) $r->leave,
        ]);

        if (! $csv) {
            return response()->json(['data' => $rows, 'days' => iterator_count(CarbonPeriod::create($f['from'], $f['to']))]);
        }

        AuditLogger::record('attendance', 'export', null, null, ['from' => $f['from'], 'to' => $f['to'], 'group_by' => $f['group_by'], 'rows' => $rows->count()]);
        $filename = "attendance-{$f['group_by']}-{$f['from']}-{$f['to']}.csv";

        return Csv::download($filename, ['code', 'name', 'present', 'late', 'half_day', 'absent', 'leave'],
            $rows->map(fn ($r) => [$r['code'], $r['label'], $r['present'], $r['late'], $r['half_day'], $r['absent'], $r['leave']]));
    }
}
