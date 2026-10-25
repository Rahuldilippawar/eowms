<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Access\AccessContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes audit entries. Never pass passwords, tokens or other secrets in
 * $old / $new — callers send only the fields that matter for the record.
 */
final class AuditLogger
{
    /**
     * Records only the attributes that actually changed between $before and
     * the model's current state (hidden attributes are never included).
     *
     * @param  array<string, mixed>  $before  $model->getAttributes() taken before the change
     */
    public static function recordChanges(string $module, string $action, Model $model, array $before, ?string $reason = null): ?AuditLog
    {
        $hidden = array_flip($model->getHidden());
        $after = $model->getAttributes();
        $old = [];
        $new = [];
        foreach ($after as $key => $value) {
            if (isset($hidden[$key]) || in_array($key, ['updated_at', 'created_at', 'updated_by', 'created_by'], true)) {
                continue;
            }
            if (! array_key_exists($key, $before) || (string) $before[$key] !== (string) $value) {
                $old[$key] = $before[$key] ?? null;
                $new[$key] = $value;
            }
        }
        if ($new === []) {
            return null;
        }

        return self::record($module, $action, $model, $old, $new, $reason);
    }

    /** Snapshot of a new model's non-hidden attributes, for "created" entries. */
    public static function snapshot(Model $model): array
    {
        return array_diff_key($model->attributesToArray(), array_flip(['created_at', 'updated_at']));
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function record(
        string $module,
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        ?int $userId = null,
        ?int $organizationId = null,
    ): AuditLog {
        $context = AccessContext::current();
        $request = request();

        return AuditLog::create([
            'organization_id' => $organizationId ?? ($context?->organization()?->id) ?? ($subject?->getAttribute('organization_id')),
            'user_id' => $userId ?? $context?->user()->id ?? $request?->user()?->id,
            'module' => $module,
            'action' => $action,
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'reason' => $reason,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}
