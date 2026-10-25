<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Task;
use App\Services\AttendanceRules;
use App\Support\Access\AccessContext;
use App\Support\Queries\PeopleQueries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * KPIs and chart series from live data, each limited to what the user may
 * see. A KPI the user has no permission for is omitted (null), never zero.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $context = AccessContext::current();
        $rules = AttendanceRules::for($context->organization());
        $f = $request->validate([
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'year', 'custom'])],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'institution_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
        ]);
        [$from, $to] = $this->range($f['period'] ?? 'month', $f['from'] ?? null, $f['to'] ?? null, $rules->now());
        $today = $rules->now()->toDateString();

        $filter = function ($q, string $prefix = 'employees') use ($f) {
            return $q->when($f['institution_id'] ?? null, fn ($w, $id) => $w->where("{$prefix}.institution_id", $id))
                ->when($f['department_id'] ?? null, fn ($w, $id) => $w->where("{$prefix}.department_id", $id));
        };

        $kpis = ['employees' => null, 'present_today' => null, 'on_leave_today' => null, 'absent_today' => null, 'students' => null, 'pending_leave_approvals' => null, 'pending_corrections' => null, 'my_open_tasks' => null, 'tasks_to_verify' => null, 'overdue_tasks' => null];
        $charts = ['attendance_trend' => null, 'by_department' => null, 'by_institution' => null];

        if ($context->can('employees.view')) {
            $employees = $filter(PeopleQueries::employees()->where('employees.is_active', true));
            $kpis['employees'] = (clone $employees)->count();
            $charts['by_institution'] = $this->group((clone $employees), 'institution_id', 'institutions');
            $charts['by_department'] = $this->group((clone $employees), 'department_id', 'departments');
        }

        if ($context->can('attendance.view')) {
            $ids = $filter(PeopleQueries::employees('attendance.view')->where('employees.is_active', true))->pluck('employees.id');
            $todayCounts = DB::table('attendance_records')->whereIn('employee_id', $ids)->where('work_date', $today)
                ->selectRaw("SUM(status IN ('present','late','half_day')) as present, SUM(status = 'leave') as on_leave")->first();
            $kpis['present_today'] = (int) ($todayCounts->present ?? 0);
            $kpis['on_leave_today'] = (int) ($todayCounts->on_leave ?? 0);
            // Not checked in and not on leave counts as absent for today.
            $kpis['absent_today'] = max(0, $ids->count() - $kpis['present_today'] - $kpis['on_leave_today']);

            $rows = DB::table('attendance_records')->whereIn('employee_id', $ids)->whereBetween('work_date', [$from, $to])
                ->groupBy('work_date')->orderBy('work_date')
                ->selectRaw("work_date, SUM(status IN ('present','late','half_day')) as present, SUM(status = 'late') as late, SUM(status = 'absent') as absent, SUM(status = 'leave') as on_leave")
                ->get()->keyBy(fn ($r) => (string) $r->work_date);
            $charts['attendance_trend'] = collect(CarbonPeriod::create($from, $to))->map(function ($d) use ($rows) {
                $r = $rows->get($d->toDateString());

                return ['date' => $d->toDateString(), 'present' => (int) ($r->present ?? 0), 'late' => (int) ($r->late ?? 0), 'absent' => (int) ($r->absent ?? 0), 'leave' => (int) ($r->on_leave ?? 0)];
            })->values();
        }

        if ($context->can('students.view')) {
            $kpis['students'] = PeopleQueries::students()->where('is_active', true)
                ->when($f['institution_id'] ?? null, fn ($w, $id) => $w->where('institution_id', $id))
                ->when($f['department_id'] ?? null, fn ($w, $id) => $w->where('department_id', $id))->count();
        }

        // Leave approvals waiting for this user (as reporting officer or approver).
        $myEmployeeIds = Employee::query()->where('user_id', $request->user()->id)->pluck('id');
        $kpis['pending_leave_approvals'] = LeaveRequest::query()->where('status', 'requested')->whereNotIn('employee_id', $myEmployeeIds)
            ->where(function ($w) use ($context, $myEmployeeIds) {
                $w->whereIn('reviewer_employee_id', $myEmployeeIds ?: [0]);
                if ($context->can('leave.approve')) {
                    $w->orWhereIn('employee_id', PeopleQueries::employeeIds('leave.approve'));
                }
            })->count();

        if ($context->can('attendance.approve')) {
            $kpis['pending_corrections'] = PeopleQueries::corrections()->where('status', 'pending')->whereNotIn('employee_id', $myEmployeeIds)->count();
        }

        $mine = PeopleQueries::myEmployee();
        if ($mine !== null) {
            $kpis['my_open_tasks'] = Task::query()->where('assignee_employee_id', $mine->id)->whereIn('status', ['open', 'in_progress'])->count();
        }
        // Submitted tasks this user can verify: ones they assigned, their direct reports', or within tasks.edit.
        $kpis['tasks_to_verify'] = Task::query()->where('status', 'submitted')->whereNotIn('assignee_employee_id', $myEmployeeIds)
            ->where(function ($w) use ($request, $context, $mine) {
                $w->where('assigned_by', $request->user()->id);
                if ($mine !== null) {
                    $w->orWhereIn('assignee_employee_id', Employee::query()->where('reporting_officer_id', $mine->id)->select('id'));
                }
                if ($context->can('tasks.edit')) {
                    $w->orWhereIn('assignee_employee_id', PeopleQueries::employeeIds('tasks.edit'));
                }
            })->count();
        if ($context->can('tasks.view')) {
            $kpis['overdue_tasks'] = $filter(Task::query()->whereIn('assignee_employee_id', PeopleQueries::employeeIds('tasks.view')), 'tasks')
                ->whereIn('status', Task::OPEN_STATES)->where('due_date', '<', $today)->count();
        }

        return response()->json(['data' => ['from' => $from, 'to' => $to, 'today' => $today, 'kpis' => $kpis, 'charts' => $charts]]);
    }

    /** @return array{0: string, 1: string} */
    private function range(string $period, ?string $from, ?string $to, CarbonImmutable $now): array
    {
        return match ($period) {
            'today' => [$now->toDateString(), $now->toDateString()],
            // Explicit Monday: Carbon's default follows the request locale (Sunday for mr).
            'week' => [$now->startOfWeek(CarbonInterface::MONDAY)->toDateString(), $now->toDateString()],
            'year' => [$now->startOfYear()->toDateString(), $now->toDateString()],
            // Custom ranges are capped at one year so the trend stays readable.
            'custom' => [$from, min($to, CarbonImmutable::parse($from)->addYear()->toDateString())],
            default => [$now->startOfMonth()->toDateString(), $now->toDateString()],
        };
    }

    private function group($query, string $column, string $table): array
    {
        return $query->leftJoin("{$table} as g", 'g.id', '=', "employees.{$column}")
            ->groupBy("employees.{$column}", 'g.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get([DB::raw("employees.{$column} as id"), DB::raw('g.name as name'), DB::raw('COUNT(*) as total')])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'total' => (int) $r->total])->all();
    }
}
