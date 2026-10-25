<?php

namespace App\Support\Queries;

use App\Enums\PermissionScope;
use App\Models\Department;
use App\Models\Institution;
use App\Models\User;
use App\Support\Access\AccessContext;
use App\Support\Access\ResolvedScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Permission-scoped queries for the organization structure. Each method
 * returns only the rows the current user may see for the given permission
 * (the organization limit itself comes from the model's global scope).
 */
final class ScopedQueries
{
    /** @return Builder<Institution> */
    public static function institutions(string $permission = 'institutions.view'): Builder
    {
        $scope = self::scopeOrFail($permission);
        $query = Institution::query();

        // A department-level user sees the institutions their departments belong to.
        if ($scope->scope === PermissionScope::Departments) {
            $institutionIds = Department::query()->whereIn('id', $scope->departmentIds)
                ->whereNotNull('institution_id')->pluck('institution_id')->all();

            return $query->whereIn('id', $institutionIds ?: [0]);
        }

        return $scope->apply($query, ['institution' => 'id', 'owner' => 'created_by']);
    }

    /** @return Builder<Department> */
    public static function departments(string $permission = 'departments.view'): Builder
    {
        $scope = self::scopeOrFail($permission);

        return $scope->apply(Department::query(), [
            'institution' => 'institution_id',
            'department' => 'id',
            'owner' => 'created_by',
        ]);
    }

    /**
     * Users who hold a role in the current organization, limited to the
     * caller's scope: an institution-level caller sees people assigned to
     * those institutions (or their departments), a department-level caller
     * people assigned to those departments, "own" the people they assigned.
     *
     * @return Builder<User>
     */
    public static function users(string $permission = 'users.view'): Builder
    {
        $scope = self::scopeOrFail($permission);
        $orgId = AccessContext::current()->organizationId();

        $assignments = DB::table('role_assignments as ra')->where('ra.organization_id', $orgId);
        $assignments = match ($scope->scope) {
            PermissionScope::Organization => $assignments,
            PermissionScope::Institutions => $assignments->where(function ($q) use ($scope) {
                $ids = $scope->institutionIds ?: [0];
                $q->whereExists(fn ($e) => $e->from('role_assignment_institution as rai')->whereColumn('rai.role_assignment_id', 'ra.id')->whereIn('rai.institution_id', $ids))
                    ->orWhereExists(fn ($e) => $e->from('role_assignment_department as rad')->join('departments as d', 'd.id', '=', 'rad.department_id')
                        ->whereColumn('rad.role_assignment_id', 'ra.id')->whereIn('d.institution_id', $ids));
            }),
            PermissionScope::Departments => $assignments->whereExists(fn ($e) => $e->from('role_assignment_department as rad')
                ->whereColumn('rad.role_assignment_id', 'ra.id')->whereIn('rad.department_id', $scope->departmentIds ?: [0])),
            PermissionScope::Own => $assignments->where('ra.created_by', $scope->userId),
            PermissionScope::Assigned => $assignments->whereRaw('1 = 0'),
        };

        return User::query()->whereIn('users.id', $assignments->select('ra.user_id'));
    }

    private static function scopeOrFail(string $permission): ResolvedScope
    {
        $scope = AccessContext::current()?->scope($permission);
        abort_if($scope === null, 403, __('auth.forbidden'));

        return $scope;
    }
}
