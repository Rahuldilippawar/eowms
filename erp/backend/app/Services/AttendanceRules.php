<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Attendance settings: the organization rule (organizations.settings.attendance)
 * with defaults, optionally overridden per employee (employee_shifts).
 * Times are local to the organization's timezone. Weekly offs are ISO day
 * numbers (1 = Monday … 7 = Sunday).
 */
final class AttendanceRules
{
    public const DEFAULTS = [
        'timezone' => 'Asia/Kolkata',
        'work_start' => '09:30',
        'work_end' => '17:30',
        'late_grace_minutes' => 15,
        'half_day_below_hours' => 4,
        'max_shift_hours' => 18,
        'weekly_offs' => [7],
    ];

    /** @param  list<int>  $weeklyOffs */
    public function __construct(
        public readonly string $timezone = 'Asia/Kolkata',
        public readonly string $workStart = '09:30',
        public readonly int $lateGraceMinutes = 15,
        public readonly float $halfDayBelowHours = 4.0,
        /** How long after check-in an open record can still be checked out (overnight shifts). */
        public readonly int $maxShiftHours = 18,
        public readonly string $workEnd = '17:30',
        public readonly array $weeklyOffs = [7],
    ) {}

    /** @return array<string, mixed> the organization's rule with defaults filled in */
    public static function settings(Organization $organization): array
    {
        return array_replace(self::DEFAULTS, (array) (($organization->settings ?? [])['attendance'] ?? []));
    }

    public static function for(Organization $organization): self
    {
        $s = self::settings($organization);

        return new self(
            timezone: (string) $s['timezone'],
            workStart: (string) $s['work_start'],
            lateGraceMinutes: (int) $s['late_grace_minutes'],
            halfDayBelowHours: (float) $s['half_day_below_hours'],
            maxShiftHours: (int) $s['max_shift_hours'],
            workEnd: (string) $s['work_end'],
            weeklyOffs: array_values(array_map('intval', (array) $s['weekly_offs'])),
        );
    }

    /** The organization rule with the employee's own shift laid over it. */
    public static function forEmployee(Employee $employee): self
    {
        $org = self::for($employee->organization);
        $shift = $employee->relationLoaded('shift') ? $employee->shift : $employee->shift()->first();
        if ($shift === null) {
            return $org;
        }

        return new self(
            timezone: $org->timezone,
            workStart: $shift->work_start ?? $org->workStart,
            lateGraceMinutes: $shift->late_grace_minutes ?? $org->lateGraceMinutes,
            halfDayBelowHours: $shift->half_day_below_hours ?? $org->halfDayBelowHours,
            maxShiftHours: $org->maxShiftHours,
            workEnd: $shift->work_end ?? $org->workEnd,
            weeklyOffs: $shift->weekly_offs !== null ? array_values(array_map('intval', $shift->weekly_offs)) : $org->weeklyOffs,
        );
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    /** The calendar date a shift starting at $at belongs to. */
    public function workDate(CarbonImmutable $at): string
    {
        return $at->setTimezone($this->timezone)->toDateString();
    }

    public function isLate(CarbonImmutable $checkIn): bool
    {
        $local = $checkIn->setTimezone($this->timezone);
        $limit = CarbonImmutable::parse($local->toDateString().' '.$this->workStart, $this->timezone)->addMinutes($this->lateGraceMinutes);

        return $local->greaterThan($limit);
    }

    public function isWeeklyOff(string $date): bool
    {
        return in_array(CarbonImmutable::parse($date)->isoWeekday(), $this->weeklyOffs, true);
    }
}
