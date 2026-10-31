<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Notifications\NotificationGroups;
use App\Services\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/** A person's own language, push devices and per-group push / e-mail choices (erp/docs/NOTIFICATIONS.md). */
class NotificationSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->settings($request);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['sometimes', Rule::in(['mr', 'en'])],
            'push' => ['sometimes', 'array'],
            'push.*' => ['boolean'],
            'email' => ['sometimes', 'array'],
            'email.*' => ['boolean'],
        ]);
        $user = $request->user();
        $settings = NotificationGroups::settings($user);
        foreach (['push', 'email'] as $channel) {
            foreach (array_intersect_key($data[$channel] ?? [], array_flip(NotificationGroups::GROUPS)) as $group => $on) {
                $settings[$channel][$group] = (bool) $on;
            }
        }
        $user->forceFill(['notification_settings' => $settings] + (isset($data['locale']) ? ['locale' => $data['locale']] : []))->save();

        return $this->settings($request);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:120'],
            'keys.auth' => ['required', 'string', 'max:40'],
        ]);
        $raw = WebPush::b64d($data['keys']['p256dh']);
        abort_unless(strlen($raw) === 65 && $raw[0] === "\x04" && strlen(WebPush::b64d($data['keys']['auth'])) === 16, 422, __('validation.invalid'));
        PushSubscription::query()->updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $request->user()->id, 'endpoint' => $data['endpoint'], 'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'], 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return $this->settings($request);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $request->user()->pushSubscriptions()->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return $this->settings($request);
    }

    /** A test message to each of the person's own devices. */
    public function test(Request $request, WebPush $push): JsonResponse
    {
        abort_unless(WebPush::configured(), 409, __('notifications.push_not_configured'));
        $sent = 0;
        foreach ($request->user()->pushSubscriptions as $sub) {
            try {
                $result = $push->send($sub, ['title' => config('app.name'), 'body' => '✓', 'url' => '/my/notifications', 'tag' => 'test']);
            } catch (Throwable $e) {
                report($e);
                $result = 'failed';
            }
            if ($result === 'gone') {
                $sub->delete();
            }
            $sent += $result === 'sent' ? 1 : 0;
        }

        return response()->json(['data' => ['sent' => $sent]]);
    }

    private function settings(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();

        return response()->json(['data' => [
            'locale' => $user->locale,
            'groups' => NotificationGroups::GROUPS,
            ...NotificationGroups::settings($user),
            'push_key' => WebPush::configured() ? config('services.vapid.public_key') : null,
            'devices' => $user->pushSubscriptions()->orderByDesc('id')->get()->map(fn ($s) => [
                'id' => $s->id, 'user_agent' => $s->user_agent, 'endpoint_hash' => $s->endpoint_hash,
                'last_sent_at' => $s->last_sent_at?->toIso8601String(), 'created_at' => $s->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }
}
