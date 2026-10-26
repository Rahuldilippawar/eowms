<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesMyEmployee;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceRules;
use App\Services\AttendanceSheet;
use App\Support\Access\AccessContext;
use App\Support\Access\EmployeeAccess;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Queries\PeopleQueries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Monthly grid, movement register, attendance certificate and salary (pay-days) report. */
class AttendanceReportController extends Controller
{
    use ResolvesMyEmployee;

    public function __construct(private readonly AttendanceSheet $sheet) {}

    /** Employees × days of one month; `format=csv` needs attendance.export. */
    public function grid(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);
        [$from, $to] = $this->month($f['month']);
        $query = $this->people('attendance.view', $f);
        $today = $this->today();

        if (($f['format'] ?? 'json') === 'csv') {
            abort_unless(AccessContext::current()->can('attendance.export'), 403, __('auth.forbidden'));
            $employees = $query->limit(2000)->get();
            $sheet = $this->sheet->build($employees, $from, $to, $today);
            AuditLogger::record('attendance', 'export_grid', null, null, ['month' => $f['month'], 'rows' => $employees->count()]);
            $dates = array_keys(reset($sheet)['days'] ?? []);

            return Csv::download("attendance-grid-{$f['month']}.csv", ['code', 'name', ...array_map(fn ($d) => substr($d, 8), $dates), 'present', 'late', 'half_day', 'absent', 'leave', 'unpaid_leave', 'holiday', 'weekly_off'],
                $employees->map(fn (Employee $e) => [$e->employee_code, $e->name, ...array_values(array_map(fn ($c) => $c ?? '', $sheet[$e->id]['days'])),
                    ...array_map(fn ($k) => $sheet[$e->id]['totals'][$k], ['present', 'late', 'half_day', 'absent', 'leave', 'unpaid_leave', 'holiday', 'weekly_off'])]));
        }

        $page = $query->paginate(50)->withQueryString();
        $sheet = $this->sheet->build(collect($page->items()), $from, $to, $today);

        return response()->json([
            'data' => collect($page->items())->map(fn (Employee $e) => ['employee' => ['id' => $e->id, 'name' => $e->name, 'employee_code' => $e->employee_code]] + $sheet[$e->id]),
            'meta' => $this->meta($page),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** In / out times per day (the old movement register). */
    public function movement(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);
        abort_if(CarbonImmutable::parse($f['from'])->diffInDays(CarbonImmutable::parse($f['to'])) > 92, 422, __('validation.max.numeric', ['attribute' => 'range', 'max' => 92]));
        $tz = AttendanceRules::for(AccessContext::current()->organization())->timezone;
        $query = AttendanceRecord::query()->with('employee')
            ->whereIn('employee_id', PeopleQueries::employeeIds('attendance.view'))
            ->whereBetween('work_date', [$f['from'], $f['to']])
            ->when($f['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->orderBy('work_date')->orderBy('check_in_at');
        $row = function (AttendanceRecord $r) use ($tz) {
            $in = $r->check_in_at ? CarbonImmutable::parse($r->check_in_at)->setTimezone($tz) : null;
            $out = $r->check_out_at ? CarbonImmutable::parse($r->check_out_at)->setTimezone($tz) : null;

            return [
                'employee' => ['id' => $r->employee->id, 'name' => $r->employee->name, 'employee_code' => $r->employee->employee_code],
                'work_date' => $r->work_date->toDateString(), 'status' => $r->status->value, 'source' => $r->source,
                'in' => $in?->format('H:i'), 'out' => $out?->format('H:i'), 'out_next_day' => $in && $out && $out->toDateString() !== $in->toDateString(),
                'hours' => $in && $out ? round($in->diffInMinutes($out) / 60, 2) : null,
            ];
        };

        if (($f['format'] ?? 'json') === 'csv') {
            abort_unless(AccessContext::current()->can('attendance.export'), 403, __('auth.forbidden'));
            $rows = $query->limit(20000)->get()->map($row);
            AuditLogger::record('attendance', 'export_movement', null, null, ['from' => $f['from'], 'to' => $f['to'], 'rows' => $rows->count()]);

            return Csv::download("movement-{$f['from']}-{$f['to']}.csv", ['date', 'code', 'name', 'status', 'in', 'out', 'hours', 'source'],
                $rows->map(fn ($r) => [$r['work_date'], $r['employee']['employee_code'], $r['employee']['name'], $r['status'], $r['in'], $r['out'], $r['hours'], $r['source']]));
        }

        $page = $query->paginate(100)->withQueryString();

        return response()->json(['data' => collect($page->items())->map($row), 'meta' => $this->meta($page)]);
    }

    /** Data for a printable attendance certificate of one employee. */
    public function certificate(Request $request, Employee $employee): JsonResponse
    {
        abort_unless(EmployeeAccess::isSelf($employee) || EmployeeAccess::reaches($employee, 'attendance.view'), 404);
        $f = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        abort_if(CarbonImmutable::parse($f['from'])->diffInDays(CarbonImmutable::parse($f['to'])) > 366, 422, __('validation.max.numeric', ['attribute' => 'range', 'max' => 366]));
        $employee->load(['department', 'institution', 'organization']);
        $sheet = $this->sheet->build(collect([$employee]), $f['from'], $f['to'], $this->today())[$employee->id];
        AuditLogger::record('attendance', 'certificate', $employee, null, ['from' => $f['from'], 'to' => $f['to']]);

        return response()->json(['data' => [
            'employee' => ['name' => $employee->name, 'employee_code' => $employee->employee_code, 'designation' => $employee->designation, 'department' => $employee->department?->name, 'institution' => $employee->institution?->name, 'joining_date' => $employee->joining_date?->toDateString()],
            'organization' => $employee->organization->name,
            'from' => $f['from'], 'to' => $f['to'],
            'totals' => $sheet['totals'],
            'issued_on' => $this->today(),
        ]]);
    }

    public function myCertificate(Request $request): JsonResponse
    {
        return $this->certificate($request, $this->employeeOrFail());
    }

    /**
     * Pay days per employee for a month: the month's days minus absent days,
     * unpaid leave, working days with no record and half of each half day.
     * Pay = monthly salary × pay days ÷ days in month. Statutory deductions
     * are not computed. Needs employees.sensitive and attendance.view.
     */
    public function salary(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);
        [$from, $to] = $this->month($f['month']);
        $employees = $this->people('employees.sensitive', $f)->whereIn('employees.id', PeopleQueries::employeeIds('attendance.view'))
            ->with(['private', 'department'])->limit(2000)->get();
        // Days still to come are not counted against pay.
        $sheet = $this->sheet->build($employees, $from, $to, $this->today());
        $daysInMonth = CarbonImmutable::parse($from)->daysInMonth;

        $rows = $employees->map(function (Employee $e) use ($sheet, $daysInMonth) {
            $t = $sheet[$e->id]['totals'];
            $unpaid = $t['absent'] + $t['unpaid_leave'] + $t['unmarked'] + $t['half_day'] * 0.5;
            $payDays = max(0, $daysInMonth - $unpaid);
            $salary = $e->private?->monthly_salary === null ? null : (float) $e->private->monthly_salary;

            return [
                'employee' => ['id' => $e->id, 'name' => $e->name, 'employee_code' => $e->employee_code, 'department' => $e->department?->name],
                'monthly_salary' => $salary, 'days_in_month' => $daysInMonth, 'pay_days' => $payDays, 'unpaid_days' => $unpaid,
                'totals' => $t, 'pay' => $salary === null ? null : round($salary * $payDays / $daysInMonth, 2),
            ];
        })->values();
        AuditLogger::record('attendance', 'salary_report', null, null, ['month' => $f['month'], 'rows' => $rows->count(), 'format' => $f['format'] ?? 'json']);

        if (($f['format'] ?? 'json') === 'csv') {
            return Csv::download("salary-{$f['month']}.csv", ['code', 'name', 'department', 'monthly_salary', 'days_in_month', 'present', 'half_day', 'leave', 'unpaid_leave', 'absent', 'unmarked', 'holiday', 'weekly_off', 'pay_days', 'pay'],
                $rows->map(fn ($r) => [$r['employee']['employee_code'], $r['employee']['name'], $r['employee']['department'], $r['monthly_salary'], $r['days_in_month'],
                    $r['totals']['present'], $r['totals']['half_day'], $r['totals']['leave'], $r['totals']['unpaid_leave'], $r['totals']['absent'], $r['totals']['unmarked'],
                    $r['totals']['holiday'], $r['totals']['weekly_off'], $r['pay_days'], $r['pay']]));
        }

        return response()->json(['data' => $rows, 'from' => $from, 'to' => $to]);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Builder<Employee>
     */
    private function people(string $permission, array $f): Builder
    {
        return PeopleQueries::employees($permission)->where('employees.is_active', true)
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('employees.department_id', $id))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('employees.name', 'like', '%'.addcslashes($s, '%_\\').'%')->orWhere('employees.employee_code', $s)))
            ->orderBy('employees.name');
    }

    /** @return array{string, string} */
    private function month(string $ym): array
    {
        $start = CarbonImmutable::parse("{$ym}-01");

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    private function today(): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
    }

    /** @return array<string, int|null> */
    private function meta($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()];
    }
}
