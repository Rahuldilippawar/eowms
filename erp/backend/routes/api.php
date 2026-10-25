<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceReportController;
use App\Http\Controllers\Api\V1\AttendanceSettingsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BiometricDeviceController;
use App\Http\Controllers\Api\V1\CustomFieldController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeProfileController;
use App\Http\Controllers\Api\V1\EmployeeToolsController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationModulesController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PerformanceController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PublicVerifyController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkController;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\SetAccessContext;
use Illuminate\Support\Facades\Route;

// All routes here are served under /api/v1 (see bootstrap/app.php).

Route::get('/health', HealthController::class)->name('api.health');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.auth.login');

// Public ID-card check (QR on the card): only what the card shows.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/public/employees/{token}', [PublicVerifyController::class, 'employee']);
    Route::get('/public/employees/{token}/photo', [PublicVerifyController::class, 'photo']);
});

Route::middleware('throttle:password-reset')->group(function () {
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'sendLink'])->name('api.auth.forgot');
    Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->name('api.auth.reset');
});

Route::middleware(['auth:sanctum', SetAccessContext::class, EnsurePasswordChanged::class])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:10,1')->name('api.auth.change-password');
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
    Route::get('/me/attendance', [MeController::class, 'attendance'])->middleware('module:attendance');
    Route::post('/me/attendance/check-in', [MeController::class, 'checkIn'])->middleware('throttle:10,1')->middleware('module:attendance');
    Route::post('/me/attendance/check-out', [MeController::class, 'checkOut'])->middleware('throttle:10,1')->middleware('module:attendance');
    Route::get('/me/attendance/corrections', [MeController::class, 'corrections'])->middleware('module:attendance');
    Route::post('/me/attendance/corrections', [MeController::class, 'requestCorrection'])->middleware('module:attendance');
    Route::get('/me/leave', [MeController::class, 'leave'])->middleware('module:leave');
    Route::post('/me/leave', [MeController::class, 'applyLeave'])->middleware('module:leave');
    Route::post('/me/leave/{leave}/cancel', [MeController::class, 'cancelLeave'])->middleware('module:leave');
    Route::get('/leave-types/active', [MeController::class, 'leaveTypes'])->middleware('module:leave');
    Route::get('/leave-requests/{leave}/attachment', [MeController::class, 'attachment'])->middleware('module:leave');

    Route::get('/employees', [EmployeeController::class, 'index'])->middleware('permission:employees.view');
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('permission:employees.create');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employees.view');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.edit');
    Route::post('/employees/{employee}/active', [EmployeeController::class, 'setActive'])->middleware('permission:employees.activate');

    Route::get('/students', [StudentController::class, 'index'])->middleware('permission:students.view')->middleware('module:students');
    Route::post('/students', [StudentController::class, 'store'])->middleware('permission:students.create')->middleware('module:students');
    Route::get('/students/{student}', [StudentController::class, 'show'])->middleware('permission:students.view')->middleware('module:students');
    Route::put('/students/{student}', [StudentController::class, 'update'])->middleware('permission:students.edit')->middleware('module:students');
    Route::post('/students/{student}/active', [StudentController::class, 'setActive'])->middleware('permission:students.activate')->middleware('module:students');

    Route::get('/attendance', [AttendanceController::class, 'day'])->middleware('permission:attendance.view')->middleware('module:attendance');
    Route::put('/attendance', [AttendanceController::class, 'mark'])->middleware('module:attendance');
    Route::get('/attendance/summary', [AttendanceController::class, 'summary'])->middleware('permission:attendance.view')->middleware('module:attendance');
    Route::get('/attendance/corrections', [AttendanceController::class, 'corrections'])->middleware('permission:attendance.approve')->middleware('module:attendance');
    Route::post('/attendance/corrections/{correction}/decide', [AttendanceController::class, 'decideCorrection'])->middleware('permission:attendance.approve')->middleware('module:attendance');

    Route::get('/leave-requests', [LeaveController::class, 'index'])->middleware('permission:leave.view')->middleware('module:leave');
    Route::get('/leave-approvals', [LeaveController::class, 'approvals'])->middleware('module:leave');
    Route::post('/leave-requests/{leave}/decide', [LeaveController::class, 'decide'])->middleware('module:leave');
    Route::get('/leave-types', [LeaveController::class, 'types'])->middleware('permission:leave.manage_types')->middleware('module:leave');
    Route::post('/leave-types', [LeaveController::class, 'storeType'])->middleware('permission:leave.manage_types')->middleware('module:leave');
    Route::put('/leave-types/{leaveType}', [LeaveController::class, 'updateType'])->middleware('permission:leave.manage_types')->middleware('module:leave');

    Route::get('/me/work', [WorkController::class, 'mine'])->middleware('module:daily_work');
    Route::post('/me/work', [WorkController::class, 'store'])->middleware('module:daily_work');
    Route::put('/me/work/{entry}', [WorkController::class, 'update'])->middleware('module:daily_work');
    Route::delete('/me/work/{entry}', [WorkController::class, 'destroy'])->middleware('module:daily_work');
    Route::get('/me/tasks/open', [WorkController::class, 'myTasks'])->middleware('module:tasks');
    Route::get('/me/performance', [PerformanceController::class, 'me'])->middleware('module:performance');
    Route::get('/work', [WorkController::class, 'index'])->middleware('permission:daily_work.view')->middleware('module:daily_work');
    Route::get('/work/export', [WorkController::class, 'export'])->middleware('permission:daily_work.export')->middleware('module:daily_work');
    Route::post('/work/{entry}/review', [WorkController::class, 'review'])->middleware('permission:daily_work.review')->middleware('module:daily_work');

    // Tasks: visibility and who may act are decided per task (TaskService), not by a single permission.
    Route::get('/tasks', [TaskController::class, 'index'])->middleware('module:tasks');
    Route::get('/tasks/assignable', [TaskController::class, 'assignable'])->middleware('module:tasks');
    Route::post('/tasks', [TaskController::class, 'store'])->middleware('module:tasks');
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->middleware('module:tasks');
    Route::put('/tasks/{task}', [TaskController::class, 'update'])->middleware('module:tasks');
    Route::post('/tasks/{task}/comments', [TaskController::class, 'comment'])->middleware('module:tasks');
    Route::post('/tasks/{task}/{action}', [TaskController::class, 'transition'])->whereIn('action', ['start', 'submit', 'complete', 'reopen', 'cancel'])->middleware('module:tasks');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');

    Route::get('/performance', [PerformanceController::class, 'index'])->middleware('permission:performance.view')->middleware('module:performance');

    Route::get('/me/profile', [EmployeeProfileController::class, 'me']);
    Route::put('/me/profile', [EmployeeProfileController::class, 'updateMine']);
    Route::get('/employees/import/template', [EmployeeToolsController::class, 'importTemplate'])->middleware('permission:employees.create');
    Route::post('/employees/import/preview', [EmployeeToolsController::class, 'importPreview'])->middleware('permission:employees.create');
    Route::post('/employees/import', [EmployeeToolsController::class, 'import'])->middleware('permission:employees.create');
    Route::post('/employees/id-cards', [EmployeeToolsController::class, 'idCards'])->middleware('permission:employees.view');
    Route::post('/employees/bulk-move', [EmployeeToolsController::class, 'bulkMove'])->middleware('permission:employees.edit');
    Route::get('/employees/{employee}/profile', [EmployeeProfileController::class, 'show']);
    Route::put('/employees/{employee}/profile', [EmployeeProfileController::class, 'update']);
    Route::put('/employees/{employee}/private', [EmployeeProfileController::class, 'updatePrivate']);
    Route::post('/employees/{employee}/private/reveal', [EmployeeProfileController::class, 'revealPrivate'])->middleware('throttle:20,1');
    Route::get('/employees/{employee}/photo', [EmployeeProfileController::class, 'photo']);
    Route::post('/employees/{employee}/photo', [EmployeeProfileController::class, 'uploadPhoto']);
    Route::post('/employees/{employee}/documents', [EmployeeProfileController::class, 'uploadDocument']);
    Route::get('/employee-documents/{document}', [EmployeeProfileController::class, 'downloadDocument']);
    Route::delete('/employee-documents/{document}', [EmployeeProfileController::class, 'deleteDocument']);
    Route::post('/employees/{employee}/family', [EmployeeProfileController::class, 'saveFamilyMember']);
    Route::put('/employees/{employee}/family/{member}', [EmployeeProfileController::class, 'saveFamilyMember']);
    Route::delete('/employees/{employee}/family/{member}', [EmployeeProfileController::class, 'deleteFamilyMember']);
    Route::post('/employees/{employee}/achievements', [EmployeeProfileController::class, 'addAchievement']);
    Route::delete('/employees/{employee}/achievements/{achievement}', [EmployeeProfileController::class, 'deleteAchievement']);

    Route::get('/custom-fields/active', [CustomFieldController::class, 'active']);
    Route::get('/custom-fields', [CustomFieldController::class, 'index']);
    Route::post('/custom-fields', [CustomFieldController::class, 'store']);
    Route::put('/custom-fields/{field}', [CustomFieldController::class, 'update']);
    Route::get('/organization/modules', [OrganizationModulesController::class, 'show']);
    Route::put('/organization/modules', [OrganizationModulesController::class, 'update']);

    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::get('/holidays/import/template', [HolidayController::class, 'template'])->middleware('permission:attendance.manage');
    Route::post('/holidays/import', [HolidayController::class, 'import'])->middleware('permission:attendance.manage');
    Route::post('/holidays', [HolidayController::class, 'store'])->middleware('permission:attendance.manage');
    Route::put('/holidays/{holiday}', [HolidayController::class, 'update'])->middleware('permission:attendance.manage');
    Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy'])->middleware('permission:attendance.manage');
    Route::get('/organization/attendance-rules', [AttendanceSettingsController::class, 'organization']);
    Route::put('/organization/attendance-rules', [AttendanceSettingsController::class, 'updateOrganization']);
    Route::get('/employees/{employee}/attendance-settings', [AttendanceSettingsController::class, 'employee']);
    Route::put('/employees/{employee}/attendance-settings', [AttendanceSettingsController::class, 'updateEmployee'])->middleware('module:attendance');

    Route::get('/me/leave/balances', [MeController::class, 'leaveBalances'])->middleware('module:leave');
    Route::get('/leave-balances', [LeaveBalanceController::class, 'index'])->middleware('permission:leave.view');
    Route::put('/leave-balances', [LeaveBalanceController::class, 'allocate'])->middleware('permission:leave.balances');

    Route::get('/attendance/grid', [AttendanceReportController::class, 'grid'])->middleware('permission:attendance.view');
    Route::get('/attendance/movement', [AttendanceReportController::class, 'movement'])->middleware('permission:attendance.view');
    Route::get('/attendance/certificate/{employee}', [AttendanceReportController::class, 'certificate'])->middleware('module:attendance');
    Route::get('/me/attendance/certificate', [AttendanceReportController::class, 'myCertificate'])->middleware('module:attendance');
    Route::get('/reports/salary', [AttendanceReportController::class, 'salary'])->middleware(['permission:employees.sensitive', 'permission:attendance.view']);

    Route::get('/biometric-devices', [BiometricDeviceController::class, 'index'])->middleware('permission:attendance.manage');
    Route::post('/biometric-devices', [BiometricDeviceController::class, 'store'])->middleware('permission:attendance.manage');
    Route::put('/biometric-devices/{device}', [BiometricDeviceController::class, 'update'])->middleware('permission:attendance.manage');
    Route::get('/biometric-punches', [BiometricDeviceController::class, 'punches'])->middleware('permission:attendance.manage');
});
