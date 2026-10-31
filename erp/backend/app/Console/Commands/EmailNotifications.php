<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AttendanceRules;
use App\Services\Notifications\NotificationGroups;
use App\Services\Notifications\NotificationText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One e-mail per person per run with their new notifications of the groups they chose for e-mail
 * (renewals by default), in their language (erp/docs/NOTIFICATIONS.md). Runs hourly.
 */
class EmailNotifications extends Command
{
    protected $signature = 'erp:email-notifications';

    protected $description = 'E-mail each person a summary of new notifications they chose to get by e-mail.';

    public const WINDOW_HOURS = 26;

    public function handle(): int
    {
        $mailed = 0;
        $pending = UserNotification::withoutGlobalScopes()->whereNull('emailed_at')->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->orderBy('id')->get()->groupBy('user_id');
        foreach ($pending as $userId => $rows) {
            $user = User::query()->find($userId);
            $wanted = $user && $user->is_active && $user->email
                ? $rows->filter(fn (UserNotification $n) => NotificationGroups::wants($user, 'email', $n->type))
                : collect();
            if ($wanted->isNotEmpty()) {
                $s = NotificationText::strings($user->locale);
                $base = rtrim((string) config('app.url'), '/');
                $lines = $wanted->map(function (UserNotification $n) use ($user, $base) {
                    $org = Organization::query()->find($n->organization_id);

                    return '• '.NotificationText::render($n, $user->locale, $org ? AttendanceRules::for($org)->timezone : 'Asia/Kolkata')
                        ."\n  ".$base.($n->link ?: '/notifications');
                });
                $body = NotificationText::fill($s['emailIntro'], ['name' => $user->name])."\n\n".$lines->implode("\n\n")."\n\n— ".$s['app']."\n".$s['emailFooter']."\n";
                try {
                    Mail::raw($body, fn ($m) => $m->to($user->email, $user->name)->subject(NotificationText::fill($s['emailSubject'], ['count' => $wanted->count()]).' · '.$s['app']));
                    $mailed++;
                } catch (Throwable $e) {
                    // Left for the next run (within the window) when the mail server fails.
                    report($e);

                    continue;
                }
            }
            UserNotification::withoutGlobalScopes()->whereIn('id', $rows->pluck('id'))->update(['emailed_at' => now()]);
        }
        $this->info("E-mails sent: {$mailed}");

        return self::SUCCESS;
    }
}
