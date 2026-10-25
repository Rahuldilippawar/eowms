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
