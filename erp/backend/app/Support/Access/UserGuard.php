<?php

namespace App\Support\Access;

use App\Enums\PermissionScope;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Queries\ScopedQueries;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Rules for changing another user's account or roles. */
final class UserGuard
{
    /** The user must be visible under $permission's scope (else 404). */
    public static function assertVisible(User $target, string $permission): void
    {
        abort_unless(ScopedQueries::users($permission)->whereKey($target->id)->exists(), 404);
    }

    /**
     * Account-level changes (name, email, active flag, password link) affect
     * every organization the person belongs to, so they are allowed only for
     * accounts that belong to the current organization alone, never for
     * super admins, and never on oneself for activation.
     */
    public static function assertMayChangeAccount(User $actor, User $target): void
    {
        if ($actor->is_super_admin) {
            return;
        }
        if ($target->is_super_admin) {
            throw ValidationException::withMessages(['user' => [__('access.super_admin_protected')]]);
        }
        $orgId = AccessContext::current()->organizationId();
        $elsewhere = DB::table('role_assignments')->where('user_id', $target->id)->where('organization_id', '!=', $orgId)->exists();
        if ($elsewhere) {
            throw ValidationException::withMessages(['user' => [__('access.shared_user')]]);
        }
    }

    public static function assertNotSelf(User $actor, User $target): void
    {
        if ($actor->is($target) && ! $actor->is_super_admin) {
            throw ValidationException::withMessages(['user' => [__('access.self_change')]]);
        }
    }

    /**
     * After removing $removing, someone (other than super admins) must still
     * hold roles.assign organization-wide, so the organization is never locked out.
     */
    public static function assertNotLastAdmin(RoleAssignment $removing): void
    {
        $remaining = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->join('role_permission as rp', 'rp.role_id', '=', 'ra.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ra.organization_id', $removing->organization_id)
            ->where('ra.id', '!=', $removing->id)
            ->where('u.is_active', true)
            ->where('p.key', 'roles.assign')
            ->where('rp.scope', PermissionScope::Organization->value)
            ->exists();

        $removingIsAdmin = DB::table('role_permission as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $removing->role_id)->where('p.key', 'roles.assign')
            ->where('rp.scope', PermissionScope::Organization->value)->exists();

        if ($removingIsAdmin && ! $remaining) {
            throw ValidationException::withMessages(['role' => [__('access.last_admin')]]);
        }
    }
}
