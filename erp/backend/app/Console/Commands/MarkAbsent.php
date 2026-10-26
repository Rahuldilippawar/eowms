<?php

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Organization;
use App\Services\AttendanceRules;
use App\Services\WorkCalendar;
use Illuminate\Console\Command;

/**
 * Marks "absent" for yesterday (organization time) wherever an active
 * employee had a working day and no record at all — no check-in, no leave,
 * no manual mark. Re-running is harmless: existing records are never touched.
 */
class MarkAbsent extends Command
{
    protected $signature = 'erp:mark-absent {--date= : Y-m-d, defaults to yesterday in each organization\'s timezone}';

    protected $description = 'Mark absent for working days without any attendance record.';

    public function handle(): int
    {
        $marked = 0;
        Organization::query()->where('is_active', true)->each(function (Organization $organization) use (&$marked) {
            $date = $this->option('date') ?: AttendanceRules::for($organization)->now()->subDay()->toDateString();
            Employee::withoutGlobalScopes()->where(['organization_id' => $organization->id, 'is_active' => true])
                ->where(fn ($q) => $q->whereNull('joining_date')->orWhere('joining_date', '<=', $date))
                ->where(fn ($q) => $q->whereNull('left_date')->orWhere('left_date', '>=', $date))
                ->whereNotExists(fn ($q) => $q->from('attendance_records')->whereColumn('attendance_records.employee_id', 'employees.id')->where('work_date', $date))
                ->with('shift')->each(function (Employee $employee) use ($date, &$marked) {
                    if (! WorkCalendar::isWorkingDay($employee, $date)) {
                        return;
                    }
                    AttendanceRecord::withoutGlobalScopes()->firstOrCreate(
                        ['employee_id' => $employee->id, 'work_date' => $date],
                        ['organization_id' => $employee->organization_id, 'institution_id' => $employee->institution_id, 'department_id' => $employee->department_id,
                            'status' => AttendanceStatus::Absent, 'source' => 'system', 'remarks' => 'no attendance recorded'],
                    );
                    $marked++;
                });
        });
        $this->info("Marked absent: {$marked}.");

        return self::SUCCESS;
    }
}
