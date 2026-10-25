<?php

namespace App\Support\Queries;

use App\Enums\PermissionScope;
use App\Models\Department;
use App\Models\Institution;
use App\Support\Access\AccessContext;
use App\Support\Access\ResolvedScope;
use Illuminate\Database\Eloquent\Builder;

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

    private static function scopeOrFail(string $permission): ResolvedScope
    {
        $scope = AccessContext::current()?->scope($permission);
        abort_if($scope === null, 403, __('auth.forbidden'));

        return $scope;
    }
}
