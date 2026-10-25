<?php

namespace App\Support\Queries;

use App\Enums\PermissionScope;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Student;
use App\Support\Access\AccessContext;
use App\Support\Access\ResolvedScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Permission-scoped queries for people and their attendance / leave.
 *
 * For people records "own" and "assigned" both mean the record linked to the
 * signed-in user (their own employee profile). Attendance, corrections and
 * leave follow the employee they belong to, using the employee's current
 * placement (primary or additional department).
 */
final class PeopleQueries
{
    /** @return Builder<Employee> */
    public static function employees(string $permission = 'employees.view'): Builder
    {
        return self::scopeEmployees(Employee::query(), self::scope($permission));
    }

    /** @return Builder<Student> */
    public static function students(string $permission = 'students.view'): Builder
    {
        $scope = self::scope($permission);

        return $scope->apply(Student::query(), ['institution' => 'institution_id', 'department' => 'department_id', 'owner' => 'created_by']);
    }

    /** @return Builder<AttendanceRecord> */
    public static function attendance(string $permission = 'attendance.view'): Builder
    {
        return AttendanceRecord::query()->whereIn('employee_id', self::employeeIds($permission));
    }

    /** @return Builder<AttendanceCorrection> */
    public static function corrections(string $permission = 'attendance.approve'): Builder
    {
        return AttendanceCorrection::query()->whereIn('employee_id', self::employeeIds($permission));
    }

    /** @return Builder<LeaveRequest> */
    public static function leave(string $permission = 'leave.view'): Builder
    {
        return LeaveRequest::query()->whereIn('employee_id', self::employeeIds($permission));
    }

    /** Sub-query of employee ids reachable under $permission. */
    public static function employeeIds(string $permission): Builder
    {
        return self::scopeEmployees(Employee::query(), self::scope($permission))->select('employees.id');
    }

    /** @param  Builder<Employee>  $query */
    public static function scopeEmployees(Builder $query, ResolvedScope $scope): Builder
    {
        return match ($scope->scope) {
            PermissionScope::Organization => $query,
            PermissionScope::Institutions => $query->where(function ($w) use ($scope) {
                $institutionIds = $scope->institutionIds ?: [0];
                $departmentIds = Department::query()->whereIn('institution_id', $institutionIds)->pluck('id')->all() ?: [0];
                $w->whereIn('employees.institution_id', $institutionIds)
                    ->orWhereIn('employees.department_id', $departmentIds)
                    ->orWhereExists(fn ($e) => $e->from('employee_department as ed')->whereColumn('ed.employee_id', 'employees.id')->whereIn('ed.department_id', $departmentIds));
            }),
            PermissionScope::Departments => $query->where(function ($w) use ($scope) {
                $departmentIds = $scope->departmentIds ?: [0];
                $w->whereIn('employees.department_id', $departmentIds)
                    ->orWhereExists(fn ($e) => $e->from('employee_department as ed')->whereColumn('ed.employee_id', 'employees.id')->whereIn('ed.department_id', $departmentIds));
            }),
            PermissionScope::Own, PermissionScope::Assigned => $query->where('employees.user_id', $scope->userId),
        };
    }

    /** The signed-in user's own employee record in the current organization, if any. */
    public static function myEmployee(): ?Employee
    {
        $context = AccessContext::current();

        return $context ? Employee::query()->where('user_id', $context->user()->id)->first() : null;
    }

    private static function scope(string $permission): ResolvedScope
    {
        $scope = AccessContext::current()?->scope($permission);
        abort_if($scope === null, 403, __('auth.forbidden'));

        return $scope;
    }
}
