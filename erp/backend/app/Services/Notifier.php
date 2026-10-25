<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Access\AccessContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Writes in-app notifications. The message text is rendered by the app from
 * `type` + `data`, so each reader sees it in their own language. Nobody is
 * notified about their own action, and inactive accounts get nothing.
 */
final class Notifier
{
    /** @param  array<string, mixed>  $data */
    public static function user(?int $userId, int $organizationId, string $type, array $data = [], ?string $link = null, ?string $dedupeKey = null): void
    {
        if ($userId === null || $userId === AccessContext::current()?->user()->id) {
            return;
        }
        if (! User::query()->whereKey($userId)->where('is_active', true)->exists()) {
            return;
        }
        try {
            UserNotification::withoutGlobalScopes()->create([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'type' => $type,
                'data' => $data,
                'link' => $link,
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Same reminder already sent (dedupe key) — nothing to do.
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function employee(?Employee $employee, string $type, array $data = [], ?string $link = null, ?string $dedupeKey = null): void
    {
        if ($employee !== null) {
            self::user($employee->user_id, $employee->organization_id, $type, $data, $link, $dedupeKey);
        }
    }
}
