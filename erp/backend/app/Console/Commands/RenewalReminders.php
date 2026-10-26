<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Organization;
use App\Models\RenewalItem;
use App\Services\AttendanceRules;
use App\Services\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The old app's renewal-reminder cron: 90, 60, 30, 15, 7, 3, 1 and 0 days
 * before expiry, then 1 day after and every 7 days until renewed. A missed
 * run catches up within 2 days. The responsible person hears; from 7 days
 * before (or when nobody is responsible) the department head too; when
 * neither exists, whoever created the item. Each item, milestone and
 * expiry date is reminded once.
 */
class RenewalReminders extends Command
{
    public const MILESTONES = [90, 60, 30, 15, 7, 3, 1, 0];

    private const CATCH_UP_DAYS = 2;

    protected $signature = 'erp:renewal-reminders {--date= : Pretend today is this date (Y-m-d)}';

    protected $description = 'Remind people of licences, approvals and contracts that are about to expire or have expired.';

    public static function milestone(int $daysLeft): ?string
    {
        if ($daysLeft >= 0) {
            foreach (array_reverse(self::MILESTONES) as $m) {
                if ($m >= $daysLeft && $m - $daysLeft <= self::CATCH_UP_DAYS) {
                    return 'd'.$m;
                }
            }

            return null;
        }
        $overdue = -$daysLeft;
        $k = 1 + 7 * intdiv($overdue - 1, 7);

        return $overdue - $k <= self::CATCH_UP_DAYS ? 'o'.$k : null;
    }

    public function handle(): int
    {
        $sent = 0;
        Organization::query()->where('is_active', true)->each(function (Organization $organization) use (&$sent) {
            $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : AttendanceRules::for($organization)->now()->startOfDay();
            RenewalItem::withoutGlobalScopes()->where('organization_id', $organization->id)->where('is_active', true)
                ->where('expiry_date', '<=', $today->addDays(self::MILESTONES[0])->toDateString())
                ->each(function (RenewalItem $item) use ($today, &$sent) {
                    $days = $item->daysLeft($today);
                    $milestone = self::milestone($days);
                    if ($milestone === null) {
                        return;
                    }
                    try {
                        DB::table('renewal_reminders')->insert(['renewal_item_id' => $item->id, 'milestone' => $milestone, 'expiry_date' => $item->expiry_date->toDateString(), 'created_at' => now()]);
                    } catch (UniqueConstraintViolationException) {
                        return;
                    }
                    $head = Department::withoutGlobalScopes()->whereKey($item->department_id)->value('head_user_id');
                    $recipients = array_filter([$item->responsible_user_id, ($days <= 7 || ! $item->responsible_user_id) ? $head : null]);
                    $recipients = array_values(array_unique($recipients ?: [$item->created_by]));
                    $data = ['item' => mb_substr($item->item_name, 0, 120), 'days' => abs($days), 'expiry_date' => $item->expiry_date->toDateString()];
                    $type = $days < 0 ? 'renewal.expired' : ($days === 0 ? 'renewal.today' : 'renewal.due');
                    foreach ($recipients as $userId) {
                        Notifier::user($userId, $item->organization_id, $type, $data, "/renewals/{$item->id}");
                    }
                    DB::table('renewal_reminders')->where(['renewal_item_id' => $item->id, 'milestone' => $milestone, 'expiry_date' => $item->expiry_date->toDateString()])
                        ->update(['recipients' => count($recipients)]);
                    $sent += count($recipients);
                });
        });
        $this->info("Renewal reminders checked ({$sent}).");

        return self::SUCCESS;
    }
}
