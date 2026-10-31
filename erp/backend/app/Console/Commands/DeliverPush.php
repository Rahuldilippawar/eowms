<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AttendanceRules;
use App\Services\Notifications\NotificationGroups;
use App\Services\Notifications\NotificationText;
use App\Services\Push\WebPush;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends new in-app notifications as Web Push to each person's devices, in their language
 * (erp/docs/NOTIFICATIONS.md). Runs every minute; notifications older than 6 hours are not pushed.
 */
class DeliverPush extends Command
{
    protected $signature = 'erp:deliver-push';

    protected $description = 'Send new notifications to the devices people turned push on for.';

    public const WINDOW_HOURS = 6;

    public function handle(WebPush $push): int
    {
        if (! WebPush::configured()) {
            $this->info('Push is not configured (VAPID keys).');

            return self::SUCCESS;
        }
        $sent = 0;
        $users = [];
        $zones = [];
        UserNotification::withoutGlobalScopes()->whereNull('pushed_at')->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->orderBy('id')->chunkById(200, function ($rows) use ($push, &$sent, &$users, &$zones) {
                foreach ($rows as $n) {
                    $user = $users[$n->user_id] ??= User::query()->with('pushSubscriptions')->find($n->user_id);
                    if ($user && $user->is_active && $user->pushSubscriptions->isNotEmpty() && NotificationGroups::wants($user, 'push', $n->type)) {
                        $org = $zones[$n->organization_id] ??= Organization::query()->find($n->organization_id);
                        $payload = [
                            'title' => $org?->name ?? NotificationText::strings($user->locale)['app'],
                            'body' => NotificationText::render($n, $user->locale, $org ? AttendanceRules::for($org)->timezone : 'Asia/Kolkata'),
                            'url' => $n->link ?: '/notifications',
                            'tag' => 'n'.$n->id,
                        ];
                        foreach ($user->pushSubscriptions as $sub) {
                            try {
                                $result = $push->send($sub, $payload);
                            } catch (Throwable $e) {
                                report($e);
                                $result = 'failed';
                            }
                            if ($result === 'gone') {
                                $sub->delete();
                            } elseif ($result === 'sent') {
                                $sub->forceFill(['last_sent_at' => now()])->save();
                                $sent++;
                            }
                        }
                    }
                    $n->forceFill(['pushed_at' => now()])->save();
                }
            });
        $this->info("Push messages sent: {$sent}");

        return self::SUCCESS;
    }
}
