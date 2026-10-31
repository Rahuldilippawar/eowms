<?php

namespace App\Services\Notifications;

use App\Models\User;

/**
 * Notification types grouped for each person's push / e-mail choices (erp/docs/NOTIFICATIONS.md).
 * In-app notifications are always shown; push is on and e-mail off by default, except renewals,
 * which the old app also e-mailed.
 */
final class NotificationGroups
{
    public const GROUPS = ['tasks', 'work', 'leave', 'attendance', 'letters', 'renewals', 'requests', 'grievances', 'vehicles', 'hostels', 'residency', 'assets', 'it', 'cctv', 'other'];

    private const BY_PREFIX = [
        'task' => 'tasks', 'work' => 'work', 'leave' => 'leave', 'attendance' => 'attendance', 'correction' => 'attendance',
        'celebration' => 'attendance', 'letter' => 'letters', 'renewal' => 'renewals', 'request' => 'requests', 'sla' => 'requests',
        'grievance' => 'grievances', 'vehicle' => 'vehicles', 'hostel' => 'hostels', 'residency' => 'residency',
        'assets' => 'assets', 'store' => 'assets', 'it' => 'it', 'cctv' => 'cctv',
    ];

    private const EMAIL_ON = ['renewals'];

    public static function of(string $type): string
    {
        return self::BY_PREFIX[strtok($type, '.')] ?? 'other';
    }

    /** @return array{push: array<string, bool>, email: array<string, bool>} every group, defaults filled in */
    public static function settings(User $user): array
    {
        $saved = (array) ($user->notification_settings ?? []);
        $out = ['push' => [], 'email' => []];
        foreach (self::GROUPS as $g) {
            $out['push'][$g] = (bool) ($saved['push'][$g] ?? true);
            $out['email'][$g] = (bool) ($saved['email'][$g] ?? in_array($g, self::EMAIL_ON, true));
        }

        return $out;
    }

    public static function wants(User $user, string $channel, string $type): bool
    {
        return self::settings($user)[$channel][self::of($type)];
    }
}
