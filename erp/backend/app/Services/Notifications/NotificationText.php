<?php

namespace App\Services\Notifications;

use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Notification text for Web Push and e-mail, from the app's own translations (copied to
 * resources/notification-texts by `npm run export:notification-texts`). Mirrors
 * frontend/src/features/notifications/text.ts.
 */
final class NotificationText
{
    /** @var array<string, array<string, mixed>> */
    private static array $texts = [];

    /** @return array<string, mixed> */
    public static function strings(string $locale): array
    {
        $locale = in_array($locale, ['en', 'mr'], true) ? $locale : 'mr';

        return self::$texts[$locale] ??= json_decode((string) file_get_contents(resource_path("notification-texts/{$locale}.json")), true);
    }

    public static function render(UserNotification $n, string $locale, string $timezone = 'Asia/Kolkata'): string
    {
        $s = self::strings($locale);
        $params = array_map(fn ($v) => $v === null ? '' : (is_scalar($v) ? (string) $v : json_encode($v)), (array) $n->data);
        foreach (['due_date', 'start_date', 'end_date', 'work_date', 'expiry_date'] as $k) {
            if (($params[$k] ?? '') !== '') {
                $params[$k] = self::date($params[$k], $locale);
            }
        }
        if (in_array($n->type, ['cctv.cameras_down', 'hostel.attendance_missing'], true) && ($params['date'] ?? '') !== '') {
            $params['date'] = self::date($params['date'], $locale);
        }
        if ($n->type === 'hostel.attendance_missing' && ($params['sessions'] ?? '') !== '') {
            $params['sessions'] = implode(', ', array_map(fn ($x) => $s['hostelSession'][$x] ?? $x, explode(',', $params['sessions'])));
        }
        if (str_starts_with($n->type, 'assets.') && ($params['due'] ?? '') !== '') {
            $params['due'] = self::dateTime($params['due'], $locale, $timezone);
        }
        $key = Str::replaceFirst('.', '_', $n->type);
        if ($n->type === 'assets.allocated' && ($params['due'] ?? '') !== '') {
            $key .= '_due';
        }
        if (in_array($n->type, ['leave.decided', 'correction.decided'], true) && ($params['status'] ?? '') !== '') {
            $key .= '_'.$params['status'];
        }
        if ($key === 'request_status' && isset($params['status'])) {
            $params['status'] = $s['requestStatus'][$params['status']] ?? $params['status'];
        }
        if ($key === 'grievance_status' && isset($params['status'])) {
            $params['status'] = $s['grievanceStatus'][$params['status']] ?? $params['status'];
        }

        return self::fill($s['types'][$key] ?? $s['generic'], $params);
    }

    /** @param array<string, string|int> $params i18next-style {{name}} placeholders */
    public static function fill(string $text, array $params): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => (string) ($params[$m[1]] ?? ''), $text);
    }

    private static function date(string $ymd, string $locale): string
    {
        try {
            return CarbonImmutable::parse(substr($ymd, 0, 10))->locale($locale === 'mr' ? 'mr' : 'en')->isoFormat('D MMM YYYY');
        } catch (\Throwable) {
            return $ymd;
        }
    }

    private static function dateTime(string $iso, string $locale, string $timezone): string
    {
        try {
            return CarbonImmutable::parse($iso)->setTimezone($timezone)->locale($locale === 'mr' ? 'mr' : 'en')->isoFormat('D MMM YYYY, h:mm A');
        } catch (\Throwable) {
            return $iso;
        }
    }
}
