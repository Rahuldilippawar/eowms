<?php

namespace App\Support\Forms;

use Carbon\CarbonImmutable;

/** Dates as people type them in spreadsheets: 2026-06-30, 30/06/2026, 3/6/2026, 30-06-2026, 30.06.2026. */
final class Dates
{
    public static function parse(string $value): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'd.m.Y'] as $format) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }
            // Round-trip check rejects overflow such as 31/02/2026.
            if ($d !== false && $d !== null && $d->format($format) === $value) {
                return $d->toDateString();
            }
        }

        return null;
    }
}
