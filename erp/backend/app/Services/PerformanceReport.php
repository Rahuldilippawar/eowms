<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-employee figures for a date range, built from attendance, the daily
 * work log and tasks (docs/WORK-AND-TASKS.md#performance-report).
 * Tasks count when their due date falls in the range; cancelled tasks are ignored.
 */
final class PerformanceReport
{
    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Collection $employees, string $from, string $to, string $today): Collection
    {
        $ids = $employees->pluck('id')->all() ?: [0];

        $attendance = DB::table('attendance_records')->whereIn('employee_id', $ids)->whereBetween('work_date', [$from, $to])
            ->groupBy('employee_id')->select('employee_id')
            ->selectRaw("SUM(status IN ('present','late','half_day')) as present, SUM(status = 'late') as late, SUM(status = 'half_day') as half_day, SUM(status = 'absent') as absent, SUM(status = 'leave') as on_leave")
            ->get()->keyBy('employee_id');

        $work = DB::table('work_entries')->whereIn('employee_id', $ids)->whereBetween('work_date', [$from, $to])
            ->groupBy('employee_id')->select('employee_id')
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(hours), 0) as hours, COUNT(DISTINCT work_date) as days_logged, SUM(reviewed_at IS NOT NULL) as reviewed')
            ->get()->keyBy('employee_id');

        $open = "'".implode("','", Task::OPEN_STATES)."'";
        $tasks = DB::table('tasks')->whereIn('assignee_employee_id', $ids)->whereBetween('due_date', [$from, $to])->where('status', '!=', 'cancelled')
            ->groupBy('assignee_employee_id')->select('assignee_employee_id')
            ->selectRaw("COUNT(*) as assigned, SUM(status = 'completed') as completed, SUM(status = 'completed' AND DATE(completed_at) <= due_date) as on_time, SUM(status IN ({$open}) AND due_date < ?) as overdue", [$today])
            ->get()->keyBy('assignee_employee_id');

        return $employees->map(function (Employee $e) use ($attendance, $work, $tasks) {
            $a = $attendance->get($e->id);
            $w = $work->get($e->id);
            $t = $tasks->get($e->id);
            $assigned = (int) ($t->assigned ?? 0);

            return [
                'employee' => ['id' => $e->id, 'name' => $e->name, 'employee_code' => $e->employee_code, 'department' => $e->department?->name],
                'attendance' => ['present' => (int) ($a->present ?? 0), 'late' => (int) ($a->late ?? 0), 'half_day' => (int) ($a->half_day ?? 0), 'absent' => (int) ($a->absent ?? 0), 'leave' => (int) ($a->on_leave ?? 0)],
                'work' => ['entries' => (int) ($w->entries ?? 0), 'hours' => round((float) ($w->hours ?? 0), 2), 'days_logged' => (int) ($w->days_logged ?? 0), 'reviewed' => (int) ($w->reviewed ?? 0)],
                'tasks' => [
                    'assigned' => $assigned, 'completed' => (int) ($t->completed ?? 0), 'on_time' => (int) ($t->on_time ?? 0), 'overdue' => (int) ($t->overdue ?? 0),
                    'on_time_rate' => $assigned > 0 ? round(((int) ($t->on_time ?? 0)) / $assigned * 100) : null,
                ],
            ];
        })->values();
    }
}
