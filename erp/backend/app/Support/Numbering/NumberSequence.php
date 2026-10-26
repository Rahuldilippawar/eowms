<?php

namespace App\Support\Numbering;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free running numbers per organization, key and period, taken under a
 * row lock so two requests never get the same number. Call inside the
 * transaction that stores the number, so a failed save gives it back.
 */
final class NumberSequence
{
    public static function next(int $organizationId, string $key, string $period): int
    {
        return DB::transaction(function () use ($organizationId, $key, $period) {
            DB::table('number_sequences')->insertOrIgnore([
                'organization_id' => $organizationId, 'key' => $key, 'period' => $period, 'last_value' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('number_sequences')->where(['organization_id' => $organizationId, 'key' => $key, 'period' => $period])
                ->lockForUpdate()->first();
            $value = (int) $row->last_value + 1;
            DB::table('number_sequences')->where('id', $row->id)->update(['last_value' => $value, 'updated_at' => now()]);

            return $value;
        });
    }

    /** Indian financial year (April–March) that contains $date: [start year, "26-27"]. */
    public static function financialYear(CarbonImmutable $date): array
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return [$start, sprintf('%02d-%02d', $start % 100, ($start + 1) % 100)];
    }
}
