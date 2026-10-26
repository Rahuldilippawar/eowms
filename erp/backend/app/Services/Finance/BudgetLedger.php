<?php

namespace App\Services\Finance;

use App\Models\BudgetTransaction;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Support\Numbering\NumberSequence;
use Carbon\CarbonImmutable;

/**
 * Department budgets (erp/docs/FINANCE.md): every spend that belongs to a
 * department is one ledger row tied to its source record, in the financial
 * year of the spend's own date. Recording again updates the row; a zero
 * amount or a deleted source removes it.
 */
final class BudgetLedger
{
    public static function fyOf(string $date): int
    {
        return NumberSequence::financialYear(CarbonImmutable::parse($date))[0];
    }

    public function record(Department $department, float $amount, string $category, string $sourceType, int $sourceId, ?string $description, string $spendDate, ?int $userId): ?BudgetTransaction
    {
        if ($amount <= 0) {
            $this->reverse($sourceType, $sourceId);

            return null;
        }

        return BudgetTransaction::withoutGlobalScopes()->updateOrCreate(
            ['source_type' => $sourceType, 'source_id' => $sourceId],
            [
                'organization_id' => $department->organization_id,
                'department_id' => $department->id,
                'fy_start_year' => self::fyOf($spendDate),
                'amount' => round($amount, 2),
                'category' => in_array($category, BudgetTransaction::CATEGORIES, true) ? $category : 'other',
                'description' => $description === null ? null : mb_substr($description, 0, 255),
                'spend_date' => $spendDate,
                'created_by' => $userId,
            ],
        );
    }

    public function reverse(string $sourceType, int $sourceId): void
    {
        BudgetTransaction::withoutGlobalScopes()->where(['source_type' => $sourceType, 'source_id' => $sourceId])->delete();
    }

    /** Left in the department's budget for the financial year of $date; null when no budget is set. */
    public function remaining(int $departmentId, string $date, ?int $exceptSourceId = null, ?string $sourceType = null): ?float
    {
        $fy = self::fyOf($date);
        $budget = DepartmentBudget::query()->where('department_id', $departmentId)->where('fy_start_year', $fy)->value('allocated_amount');
        if ($budget === null) {
            return null;
        }
        $spent = BudgetTransaction::query()->where('department_id', $departmentId)->where('fy_start_year', $fy)
            ->when($exceptSourceId && $sourceType, fn ($q) => $q->whereNot(fn ($w) => $w->where('source_type', $sourceType)->where('source_id', $exceptSourceId)))
            ->sum('amount');

        return round((float) $budget - (float) $spent, 2);
    }
}
