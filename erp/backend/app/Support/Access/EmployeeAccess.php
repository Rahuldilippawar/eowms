<?php

namespace App\Support\Access;

use App\Models\Employee;
use App\Support\Queries\PeopleQueries;

/** Per-employee checks for the profile screens (docs: erp/docs/PEOPLE.md). */
final class EmployeeAccess
{
    public static function isSelf(Employee $employee): bool
    {
        $user = AccessContext::current()?->user();

        return $user !== null && $employee->user_id === $user->id;
    }

    /** Within the caller's scope for $permission (never just because it is oneself). */
    public static function reaches(Employee $employee, string $permission): bool
    {
        return AccessContext::current()?->can($permission)
            && PeopleQueries::employees($permission)->whereKey($employee->id)->exists();
    }

    public static function mayView(Employee $employee): bool
    {
        return self::isSelf($employee) || self::reaches($employee, 'employees.view');
    }

    public static function mayEdit(Employee $employee): bool
    {
        return self::reaches($employee, 'employees.edit');
    }

    public static function maySeePrivate(Employee $employee): bool
    {
        return self::isSelf($employee) || self::reaches($employee, 'employees.sensitive');
    }
}
