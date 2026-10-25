<?php

namespace Tests\Concerns;

use App\Enums\PermissionScope;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Access\PermissionCatalog;

trait BuildsTenants
{
    protected function setUpPermissions(): void
    {
        PermissionCatalog::sync();
    }

    /**
     * A user holding one role in $org with the given permissions and scope.
     *
     * @param  list<string>  $permissions
     * @param  list<int>  $institutionIds
     * @param  list<int>  $departmentIds
     */
    protected function userWithRole(
        Organization $org,
        array $permissions,
        PermissionScope $scope = PermissionScope::Organization,
        array $institutionIds = [],
        array $departmentIds = [],
        ?User $user = null,
    ): User {
        $user ??= User::factory()->create();
        $role = Role::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'key' => 'r'.uniqid(),
            'name' => 'Test role',
        ]);
        $ids = Permission::whereIn('key', $permissions)->pluck('id');
        $role->permissions()->sync($ids->mapWithKeys(fn ($id) => [$id => ['scope' => $scope->value]])->all());

        $assignment = RoleAssignment::withoutGlobalScopes()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
        ]);
        $assignment->institutions()->sync($institutionIds);
        $assignment->departments()->sync($departmentIds);

        return $user;
    }

    /** Requests as the SPA (first-party origin) so Sanctum uses the session. */
    protected function spa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
    }
}
