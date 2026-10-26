<?php

namespace App\Support\Access;

use App\Enums\PermissionScope;
use App\Models\Department;

/** Which departments a permission scope reaches, for department-owned records (letters, budgets, purchases). */
final class ScopeDepartments
{
    /** @return list<int>|null null = every department of the organization; [] = none */
    public static function ids(?ResolvedScope $scope): ?array
    {
        return match ($scope?->scope) {
            PermissionScope::Organization => null,
            PermissionScope::Institutions => Department::query()->whereIn('institution_id', $scope->institutionIds ?: [0])->pluck('id')->all(),
            PermissionScope::Departments => $scope->departmentIds,
            default => [],
        };
    }

    public static function reaches(?ResolvedScope $scope, int $departmentId): bool
    {
        $ids = self::ids($scope);

        return $ids === null || in_array($departmentId, $ids, true);
    }
}
