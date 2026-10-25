<?php

namespace App\Services;

use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Per-organization attendance settings (organizations.settings.attendance),
 * with defaults. Times are local to the organization's timezone.
 */
final class AttendanceRules
{
    public function __construct(
        public readonly string $timezone = 'Asia/Kolkata',
        public readonly string $workStart = '09:30',
        public readonly int $lateGraceMinutes = 15,
        public readonly float $halfDayBelowHours = 4.0,
        /** How long after check-in an open record can still be checked out (overnight shifts). */
        public readonly int $maxShiftHours = 18,
    ) {}

    public static function for(Organization $organization): self
    {
        $s = (array) (($organization->settings ?? [])['attendance'] ?? []);

        return new self(
            timezone: (string) ($s['timezone'] ?? 'Asia/Kolkata'),
            workStart: (string) ($s['work_start'] ?? '09:30'),
            lateGraceMinutes: (int) ($s['late_grace_minutes'] ?? 15),
            halfDayBelowHours: (float) ($s['half_day_below_hours'] ?? 4),
            maxShiftHours: (int) ($s['max_shift_hours'] ?? 18),
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
}
