<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Letter;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * The old app's overdue-letters cron: a file that has not moved for 3 days
 * reminds its holder and its creator; after 7 days the head of the file's
 * department hears too. At most one reminder per file, person and day.
 */
class LetterReminders extends Command
{
    private const REMIND_DAYS = 3;

    private const ESCALATE_DAYS = 7;

    protected $signature = 'erp:letter-reminders';

    protected $description = 'Remind holders and creators of files that have not moved for days.';

    public function handle(): int
    {
        $sent = 0;
        $today = now()->toDateString();
        Letter::withoutGlobalScopes()->whereIn('status', Letter::MOVING)->whereNotNull('current_holder_id')
            ->where('holder_since', '<=', now()->subDays(self::REMIND_DAYS))
            ->whereHas('organization', fn ($q) => $q->where('is_active', true))
            ->chunkById(200, function ($letters) use (&$sent, $today) {
                foreach ($letters as $letter) {
                    $days = (int) $letter->holder_since->diffInDays(now());
                    $data = ['file_number' => $letter->file_number, 'subject' => mb_substr($letter->subject, 0, 120), 'days' => $days];
                    $link = "/letters/{$letter->id}";
                    $recipients = array_unique(array_filter([$letter->current_holder_id, $letter->created_by]));
                    if ($days >= self::ESCALATE_DAYS && $letter->department_id) {
                        $recipients[] = Department::withoutGlobalScopes()->whereKey($letter->department_id)->value('head_user_id');
                    }
                    foreach (array_unique(array_filter($recipients)) as $userId) {
                        $type = $userId === $letter->current_holder_id ? 'letter.overdue_holder' : 'letter.overdue';
                        Notifier::user($userId, $letter->organization_id, $type, $data, $link, "letter.overdue:{$letter->id}:{$userId}:{$today}");
                        $sent++;
                    }
                }
            });
        $this->info("Letter reminders checked ({$sent}).");

        return self::SUCCESS;
    }
}
