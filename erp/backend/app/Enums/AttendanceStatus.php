<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case HalfDay = 'half_day';
    case Absent = 'absent';
    case Leave = 'leave';

    /** Counts as "came to work" in summaries. */
    public function isPresent(): bool
    {
        return in_array($this, [self::Present, self::Late, self::HalfDay], true);
    }
}
