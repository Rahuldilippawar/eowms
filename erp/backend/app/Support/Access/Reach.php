<?php

namespace App\Support\Access;

use App\Enums\PermissionScope;
use App\Models\Department;
use Illuminate\Validation\ValidationException;

/**
 * "May this scope place a record in that department / institution?" —
 * used when creating or moving people records.
 */
final class Reach
{
    public static function department(ResolvedScope $scope, int $departmentId): bool
    {
        return match ($scope->scope) {
            PermissionScope::Organization => true,
            PermissionScope::Institutions => Department::query()->whereKey($departmentId)->whereIn('institution_id', $scope->institutionIds ?: [0])->exists(),
            PermissionScope::Departments => in_array($departmentId, $scope->departmentIds, true),
            default => false,
        };
    }

    public static function institution(ResolvedScope $scope, int $institutionId): bool
    {
        return match ($scope->scope) {
            PermissionScope::Organization => true,
            PermissionScope::Institutions => in_array($institutionId, $scope->institutionIds, true),
            PermissionScope::Departments => Department::query()->whereIn('id', $scope->departmentIds ?: [0])->where('institution_id', $institutionId)->exists(),
            default => false,
        };
    }

    /** @throws ValidationException */
    public static function assertDepartment(string $permission, int $departmentId, string $field = 'department_id'): void
    {
        $scope = AccessContext::current()?->scope($permission);
        abort_if($scope === null, 403, __('auth.forbidden'));
        if (! self::department($scope, $departmentId)) {
            throw ValidationException::withMessages([$field => [__('access.outside_scope')]]);
        }
    }

    /** @throws ValidationException */
    public static function assertInstitution(string $permission, int $institutionId, string $field = 'institution_id'): void
    {
        $scope = AccessContext::current()?->scope($permission);
        abort_if($scope === null, 403, __('auth.forbidden'));
        if (! self::institution($scope, $institutionId)) {
            throw ValidationException::withMessages([$field => [__('access.outside_scope')]]);
        }
    }
}
