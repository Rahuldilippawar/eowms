<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PerformanceController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkController;
use App\Http\Middleware\SetAccessContext;
use Illuminate\Support\Facades\Route;

// All routes here are served under /api/v1 (see bootstrap/app.php).

Route::get('/health', HealthController::class)->name('api.health');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.auth.login');

Route::middleware('throttle:password-reset')->group(function () {
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'sendLink'])->name('api.auth.forgot');
    Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->name('api.auth.reset');
});

Route::middleware(['auth:sanctum', SetAccessContext::class])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::post('/auth/switch-organization', [AuthController::class, 'switchOrganization'])->name('api.auth.switch');

    // Organizations: super admins manage all; others only their current one.
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::put('/organizations/{organization}', [OrganizationController::class, 'update']);
    Route::post('/organizations/{organization}/active', [OrganizationController::class, 'setActive']);

    Route::get('/institutions', [InstitutionController::class, 'index'])->middleware('permission:institutions.view');
    Route::post('/institutions', [InstitutionController::class, 'store'])->middleware('permission:institutions.create');
    Route::get('/institutions/{institution}', [InstitutionController::class, 'show'])->middleware('permission:institutions.view');
    Route::put('/institutions/{institution}', [InstitutionController::class, 'update'])->middleware('permission:institutions.edit');
    Route::post('/institutions/{institution}/active', [InstitutionController::class, 'setActive'])->middleware('permission:institutions.activate');

    Route::get('/departments', [DepartmentController::class, 'index'])->middleware('permission:departments.view');
    Route::post('/departments', [DepartmentController::class, 'store'])->middleware('permission:departments.create');
    Route::get('/departments/{department}', [DepartmentController::class, 'show'])->middleware('permission:departments.view');
    Route::put('/departments/{department}', [DepartmentController::class, 'update'])->middleware('permission:departments.edit');
    Route::post('/departments/{department}/active', [DepartmentController::class, 'setActive'])->middleware('permission:departments.activate');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit');
    Route::post('/users/{user}/active', [UserController::class, 'setActive'])->middleware('permission:users.activate');
    Route::post('/users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])->middleware('permission:users.reset_password');
    Route::post('/users/{user}/role-assignments', [UserController::class, 'addAssignment'])->middleware('permission:roles.assign');
    Route::delete('/role-assignments/{assignment}', [UserController::class, 'removeAssignment'])->middleware('permission:roles.assign');

    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:roles.view');
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.edit');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');

    Route::get('/dashboard', DashboardController::class);

    // Self-service: the signed-in user's own employee record.
    Route::get('/me/employee', [MeController::class, 'employee']);
    Route::get('/me/attendance', [MeController::class, 'attendance']);
    Route::post('/me/attendance/check-in', [MeController::class, 'checkIn'])->middleware('throttle:10,1');
    Route::post('/me/attendance/check-out', [MeController::class, 'checkOut'])->middleware('throttle:10,1');
    Route::get('/me/attendance/corrections', [MeController::class, 'corrections']);
    Route::post('/me/attendance/corrections', [MeController::class, 'requestCorrection']);
    Route::get('/me/leave', [MeController::class, 'leave']);
    Route::post('/me/leave', [MeController::class, 'applyLeave']);
    Route::post('/me/leave/{leave}/cancel', [MeController::class, 'cancelLeave']);
    Route::get('/leave-types/active', [MeController::class, 'leaveTypes']);
    Route::get('/leave-requests/{leave}/attachment', [MeController::class, 'attachment']);

    Route::get('/employees', [EmployeeController::class, 'index'])->middleware('permission:employees.view');
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('permission:employees.create');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employees.view');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.edit');
    Route::post('/employees/{employee}/active', [EmployeeController::class, 'setActive'])->middleware('permission:employees.activate');

    Route::get('/students', [StudentController::class, 'index'])->middleware('permission:students.view');
    Route::post('/students', [StudentController::class, 'store'])->middleware('permission:students.create');
    Route::get('/students/{student}', [StudentController::class, 'show'])->middleware('permission:students.view');
    Route::put('/students/{student}', [StudentController::class, 'update'])->middleware('permission:students.edit');
    Route::post('/students/{student}/active', [StudentController::class, 'setActive'])->middleware('permission:students.activate');

    Route::get('/attendance', [AttendanceController::class, 'day'])->middleware('permission:attendance.view');
    Route::put('/attendance', [AttendanceController::class, 'mark']);
    Route::get('/attendance/summary', [AttendanceController::class, 'summary'])->middleware('permission:attendance.view');
    Route::get('/attendance/corrections', [AttendanceController::class, 'corrections'])->middleware('permission:attendance.approve');
    Route::post('/attendance/corrections/{correction}/decide', [AttendanceController::class, 'decideCorrection'])->middleware('permission:attendance.approve');

    Route::get('/leave-requests', [LeaveController::class, 'index'])->middleware('permission:leave.view');
    Route::get('/leave-approvals', [LeaveController::class, 'approvals']);
    Route::post('/leave-requests/{leave}/decide', [LeaveController::class, 'decide']);
    Route::get('/leave-types', [LeaveController::class, 'types'])->middleware('permission:leave.manage_types');
    Route::post('/leave-types', [LeaveController::class, 'storeType'])->middleware('permission:leave.manage_types');
    Route::put('/leave-types/{leaveType}', [LeaveController::class, 'updateType'])->middleware('permission:leave.manage_types');

    Route::get('/me/work', [WorkController::class, 'mine']);
    Route::post('/me/work', [WorkController::class, 'store']);
    Route::put('/me/work/{entry}', [WorkController::class, 'update']);
    Route::delete('/me/work/{entry}', [WorkController::class, 'destroy']);
    Route::get('/me/tasks/open', [WorkController::class, 'myTasks']);
    Route::get('/me/performance', [PerformanceController::class, 'me']);
    Route::get('/work', [WorkController::class, 'index'])->middleware('permission:daily_work.view');
    Route::get('/work/export', [WorkController::class, 'export'])->middleware('permission:daily_work.export');
    Route::post('/work/{entry}/review', [WorkController::class, 'review'])->middleware('permission:daily_work.review');

    // Tasks: visibility and who may act are decided per task (TaskService), not by a single permission.
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::get('/tasks/assignable', [TaskController::class, 'assignable']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::put('/tasks/{task}', [TaskController::class, 'update']);
    Route::post('/tasks/{task}/comments', [TaskController::class, 'comment']);
    Route::post('/tasks/{task}/{action}', [TaskController::class, 'transition'])->whereIn('action', ['start', 'submit', 'complete', 'reopen', 'cancel']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');

    Route::get('/performance', [PerformanceController::class, 'index'])->middleware('permission:performance.view');
});
