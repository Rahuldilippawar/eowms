<?php

namespace App\Console\Commands;

use App\Models\AssetAllocation;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * Items given with a return time that have not come back: the holder and
 * whoever gave the item hear once a day until it is returned.
 */
class LoanOverdue extends Command
{
    protected $signature = 'erp:loan-overdue';

    protected $description = 'Remind holders of borrowed items that are past their return time.';

    public function handle(): int
    {
        $sent = 0;
        AssetAllocation::withoutGlobalScopes()->whereNull('returned_at')->whereNotNull('due_at')->where('due_at', '<', now())
            ->with(['item' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'), 'user' => fn ($q) => $q->select('id', 'name')])
            ->orderBy('id')->chunkById(200, function ($rows) use (&$sent) {
                foreach ($rows as $a) {
                    $day = now()->toDateString();
                    $data = ['item' => $a->item?->name ?? '', 'due' => $a->due_at->toIso8601String(), 'name' => $a->user?->name ?? ''];
                    Notifier::user($a->user_id, $a->organization_id, 'assets.overdue', $data, '/my/assets', "loan-overdue:{$a->id}:{$day}");
                    if ($a->allocated_by !== $a->user_id) {
                        Notifier::user($a->allocated_by, $a->organization_id, 'assets.overdue_given', $data, '/assets/allocations?view=overdue', "loan-overdue-giver:{$a->id}:{$day}");
                    }
                    $sent++;
                }
            });
        $this->info("Overdue items: {$sent}");

        return self::SUCCESS;
    }
}
