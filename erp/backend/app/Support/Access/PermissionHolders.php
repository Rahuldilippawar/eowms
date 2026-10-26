<?php

namespace App\Support\Access;

use App\Enums\PermissionScope;
use Illuminate\Support\Facades\DB;

/** Who holds a permission in an organization — for notifying handlers and escalating. */
final class PermissionHolders
{
    /**
     * Active users of the organization whose roles grant $permission; with
     * $organizationWide only those holding it across the whole organization.
     *
     * @return list<int>
     */
    public static function userIds(int $organizationId, string $permission, bool $organizationWide = false): array
    {
        return DB::table('role_assignments as ra')
            ->join('role_permission as rp', 'rp.role_id', '=', 'ra.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.organization_id', $organizationId)
            ->where('p.key', $permission)
            ->where('u.is_active', true)
            ->when($organizationWide, fn ($q) => $q->where('rp.scope', PermissionScope::Organization->value))
            ->distinct()->orderBy('ra.user_id')->pluck('ra.user_id')->map(fn ($id) => (int) $id)->all();
    }
}
