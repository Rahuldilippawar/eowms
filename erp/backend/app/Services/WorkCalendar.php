<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Holiday;
use Carbon\CarbonPeriod;

/**
 * Which days an employee is expected to work: not a weekly off (their shift
 * or the organization rule) and not a holiday of the whole organization or
 * of the employee's institution.
 */
final class WorkCalendar
{
    /** @return array<string, string> date => holiday name, for $employee between $from and $to */
    public static function holidays(Employee $employee, string $from, string $to): array
    {
        return Holiday::withoutGlobalScopes()->where('organization_id', $employee->organization_id)
            ->whereBetween('holiday_date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('institution_id')->when($employee->institution_id, fn ($w, $id) => $w->orWhere('institution_id', $id)))
            ->orderBy('holiday_date')->get()
            ->mapWithKeys(fn (Holiday $h) => [$h->holiday_date->toDateString() => $h->name])->all();
    }

    /** @return list<string> working dates between $from and $to (inclusive) */
    public static function workingDays(Employee $employee, string $from, string $to): array
    {
        $rules = AttendanceRules::forEmployee($employee);
        $holidays = self::holidays($employee, $from, $to);
        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();
            if (! $rules->isWeeklyOff($date) && ! isset($holidays[$date])) {
                $days[] = $date;
            }
        }

        return $days;
    }

    public static function isWorkingDay(Employee $employee, string $date): bool
    {
        return self::workingDays($employee, $date, $date) !== [];
    }
}
