<?php

namespace App\Support\Access;

use App\Enums\PermissionScope;
use App\Models\LeaveType;
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
            'principal' => ['name' => 'Principal', 'scope' => PermissionScope::Institutions, 'permissions' => [
                ...$structureRead, 'departments.edit', 'users.view',
                'employees.view', 'students.view', 'students.create', 'students.edit', 'students.activate', 'students.export',
                'attendance.view', 'attendance.approve', 'attendance.export', 'attendance.manage', 'leave.view', 'leave.approve', 'leave.balances',
                'daily_work.view', 'daily_work.review', 'daily_work.export', 'daily_work.import', 'tasks.view', 'tasks.create', 'tasks.edit',
                'performance.view', 'performance.export', 'letters.view', 'letters.export',
                'finance.view', 'finance.manage', 'finance.export', 'renewals.view', 'renewals.manage', 'renewals.export',
                'vehicles.view', 'vehicles.log', 'vehicles.manage', 'vehicles.export', 'maintenance.view', 'maintenance.log', 'maintenance.manage', 'maintenance.export',
                'requests.view', 'requests.manage', 'requests.export', 'grievances.manage',
                'hostels.view', 'hostels.care', 'hostels.manage', 'hostels.export', 'residency.view',
                'store.view', 'purchasing.view', 'purchasing.manage',
            ]],
            'hod' => ['name' => 'Head of Department', 'scope' => PermissionScope::Departments, 'permissions' => [
                ...$structureRead, 'users.view', 'employees.view', 'students.view',
                'attendance.view', 'attendance.create', 'attendance.approve', 'leave.view', 'leave.approve',
                'daily_work.view', 'daily_work.review', 'tasks.view', 'tasks.create', 'performance.view', 'letters.view',
                'finance.view', 'finance.manage', 'renewals.view', 'renewals.manage',
                'vehicles.view', 'vehicles.log', 'maintenance.view', 'maintenance.log', 'requests.view', 'requests.manage',
                'hostels.view', 'hostels.care', 'purchasing.view',
            ]],
            'staff' => ['name' => 'Staff', 'scope' => PermissionScope::Departments, 'permissions' => ['organizations.view', 'departments.view']],
        ];
    }

    /**
     * Adds newly declared permissions to every Organization Admin role (that
     * role always holds everything). Other roles are left as organizations
     * configured them.
     */
    public static function grantAllToOrganizationAdmins(): int
    {
        $ids = Permission::query()->pluck('id');
        $added = 0;
        Role::withoutGlobalScopes()->where(['key' => 'org_admin', 'is_system' => true])->each(function (Role $role) use ($ids, &$added) {
            $missing = $ids->diff($role->permissions()->pluck('permissions.id'));
            $role->permissions()->attach($missing->mapWithKeys(fn ($id) => [$id => ['scope' => PermissionScope::Organization->value]])->all());
            $added += $missing->count();
        });

        return $added;
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

        foreach ([['CL', 'Casual Leave', 12, true], ['SL', 'Sick Leave', 10, true], ['EL', 'Earned Leave', 15, true], ['LWP', 'Leave Without Pay', 0, false]] as [$code, $name, $days, $paid]) {
            LeaveType::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $organization->id, 'code' => $code],
                ['name' => $name, 'days_per_year' => $days, 'is_paid' => $paid, 'is_active' => true],
            );
        }
    }
}
