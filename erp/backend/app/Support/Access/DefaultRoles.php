<?php

namespace App\Support\Access;

use App\Enums\PermissionScope;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;

/**
 * Starter roles created for every new organization. Organizations can edit
 * them (except deleting) and add their own roles in Phase 3.
 */
final class DefaultRoles
{
    /** @return array<string, array{name: string, scope: PermissionScope, permissions: list<string>|'*'}> */
    public static function definitions(): array
    {
        $structureRead = ['organizations.view', 'institutions.view', 'departments.view'];

        return [
            'org_admin' => ['name' => 'Organization Admin', 'scope' => PermissionScope::Organization, 'permissions' => '*'],
            'principal' => ['name' => 'Principal', 'scope' => PermissionScope::Institutions, 'permissions' => [...$structureRead, 'departments.edit', 'users.view']],
            'hod' => ['name' => 'Head of Department', 'scope' => PermissionScope::Departments, 'permissions' => [...$structureRead, 'users.view']],
            'staff' => ['name' => 'Staff', 'scope' => PermissionScope::Departments, 'permissions' => ['organizations.view', 'departments.view']],
        ];
    }

    public static function createFor(Organization $organization): void
    {
        $allIds = Permission::query()->pluck('id', 'key');

        foreach (self::definitions() as $key => $definition) {
            $role = Role::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $organization->id, 'key' => $key],
                ['name' => $definition['name'], 'is_system' => true],
            );

            $keys = $definition['permissions'] === '*' ? $allIds->keys()->all() : $definition['permissions'];
            $sync = [];
            foreach ($keys as $permissionKey) {
                if (isset($allIds[$permissionKey])) {
                    $sync[$allIds[$permissionKey]] = ['scope' => $definition['scope']->value];
                }
            }
            $role->permissions()->sync($sync);
        }
    }
}
