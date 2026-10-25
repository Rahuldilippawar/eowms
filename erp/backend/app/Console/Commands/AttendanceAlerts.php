<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Organization;
use App\Services\AttendanceRules;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * Pattern alerts for reporting officers (the old app's attendance-pattern
 * cron): 3 or more late arrivals, or 2 or more absences, in the last 7 days.
 * At most one alert per employee and pattern per ISO week.
 */
class AttendanceAlerts extends Command
{
    protected $signature = 'erp:attendance-alerts';

    protected $description = 'Tell reporting officers about repeated late arrivals or absences.';

    public function handle(): int
    {
        $sent = 0;
        Organization::query()->where('is_active', true)->each(function (Organization $organization) use (&$sent) {
            $now = AttendanceRules::for($organization)->now();
            $from = $now->subDays(7)->toDateString();
            $week = $now->format('o-\WW');
            $counts = AttendanceRecord::withoutGlobalScopes()->where('organization_id', $organization->id)
                ->whereBetween('work_date', [$from, $now->subDay()->toDateString()])
                ->whereIn('status', ['late', 'absent'])
                ->selectRaw('employee_id, status, COUNT(*) as n')->groupBy('employee_id', 'status')->get();
            foreach ($counts as $row) {
                $status = $row->status instanceof \BackedEnum ? $row->status->value : $row->status;
                if (($status === 'late' && $row->n < 3) || ($status === 'absent' && $row->n < 2)) {
                    continue;
                }
                $employee = Employee::withoutGlobalScopes()->with('reportingOfficer')->find($row->employee_id);
                if ($employee?->reportingOfficer === null || ! $employee->is_active) {
                    continue;
                }
                Notifier::employee($employee->reportingOfficer, "attendance.pattern_{$status}", ['employee' => $employee->name, 'count' => (int) $row->n, 'days' => 7], '/attendance', "attendance.pattern_{$status}:{$employee->id}:{$week}");
                $sent++;
            }
        });
        $this->info("Attendance alerts checked ({$sent}).");

        return self::SUCCESS;
    }
}
