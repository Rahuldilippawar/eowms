<?php

use App\Http\Controllers\Api\V1\AllocationController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceReportController;
use App\Http\Controllers\Api\V1\AttendanceSettingsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BiometricDeviceController;
use App\Http\Controllers\Api\V1\CctvController;
use App\Http\Controllers\Api\V1\CustomFieldController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeadStockController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeProfileController;
use App\Http\Controllers\Api\V1\EmployeeToolsController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\GrievanceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\HostelAttendanceController;
use App\Http\Controllers\Api\V1\HostelController;
use App\Http\Controllers\Api\V1\HostelResidentController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\ItController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\LetterController;
use App\Http\Controllers\Api\V1\LetterRegisterController;
use App\Http\Controllers\Api\V1\LetterSettingsController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\MaterialPurchaseController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MyResidencyController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationModulesController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PerformanceController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PublicRequestController;
use App\Http\Controllers\Api\V1\PublicSignatureController;
use App\Http\Controllers\Api\V1\PublicVerifyController;
use App\Http\Controllers\Api\V1\PurchasingController;
use App\Http\Controllers\Api\V1\RenewalController;
use App\Http\Controllers\Api\V1\ResidencyController;
use App\Http\Controllers\Api\V1\ResidencyImportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Controllers\Api\V1\VehicleCorrectionController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\Api\V1\WorkController;
use App\Http\Controllers\Api\V1\WorkRequestController;
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
    Route::get('/public/signatures/{code}', [PublicSignatureController::class, 'show']);
    Route::get('/public/requests/{organization}', [PublicRequestController::class, 'form']);
    Route::post('/public/requests/{organization}', [PublicRequestController::class, 'submit']);
    Route::post('/public/requests/{organization}/track', [PublicRequestController::class, 'track']);
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
    Route::get('/students/export', [StudentController::class, 'export'])->middleware('permission:students.export')->middleware('module:students');
    Route::get('/students/import/template', [StudentController::class, 'importTemplate'])->middleware('permission:students.create')->middleware('module:students');
    Route::post('/students/import/preview', [StudentController::class, 'importPreview'])->middleware('permission:students.create')->middleware('module:students');
    Route::post('/students/import', [StudentController::class, 'import'])->middleware('permission:students.create')->middleware('module:students');
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
    Route::post('/me/work/{entry}/photos/{kind}', [WorkController::class, 'uploadPhoto'])->whereIn('kind', ['before', 'after'])->middleware('module:daily_work');
    Route::delete('/me/work/{entry}/photos/{kind}', [WorkController::class, 'deletePhoto'])->whereIn('kind', ['before', 'after'])->middleware('module:daily_work');
    Route::get('/work/{entry}/photos/{kind}', [WorkController::class, 'photo'])->whereIn('kind', ['before', 'after'])->middleware('module:daily_work');
    Route::get('/me/work/materials', [WorkController::class, 'materialOptions'])->middleware(['module:daily_work', 'module:store']);
    Route::get('/me/work/colleagues', [WorkController::class, 'colleagues'])->middleware(['module:daily_work', 'throttle:60,1']);
    Route::get('/me/tasks/open', [WorkController::class, 'myTasks'])->middleware('module:tasks');
    Route::get('/me/performance', [PerformanceController::class, 'me'])->middleware('module:performance');
    Route::get('/work/materials', [WorkController::class, 'materialsReport'])->middleware(['permission:daily_work.view', 'module:daily_work', 'module:store']);
    Route::get('/work', [WorkController::class, 'index'])->middleware('permission:daily_work.view')->middleware('module:daily_work');
    Route::get('/work/export', [WorkController::class, 'export'])->middleware('permission:daily_work.export')->middleware('module:daily_work');
    Route::get('/work/import/template', [WorkController::class, 'importTemplate'])->middleware('permission:daily_work.import')->middleware('module:daily_work');
    Route::post('/work/import/preview', [WorkController::class, 'importPreview'])->middleware('permission:daily_work.import')->middleware('module:daily_work');
    Route::post('/work/import', [WorkController::class, 'import'])->middleware('permission:daily_work.import')->middleware('module:daily_work');
    Route::post('/work/{entry}/review', [WorkController::class, 'review'])->middleware('permission:daily_work.review')->middleware('module:daily_work');

    // Tasks: visibility and who may act are decided per task (TaskService), not by a single permission.
    // Department finance (Phase 7).
    Route::middleware('module:finance')->prefix('finance')->group(function () {
        Route::get('/budgets', [FinanceController::class, 'budgets'])->middleware('permission:finance.view');
        Route::put('/budgets', [FinanceController::class, 'allocate'])->middleware('permission:finance.budget');
        Route::get('/budget-check', [FinanceController::class, 'check']);
        Route::get('/transactions', [FinanceController::class, 'transactions'])->middleware('permission:finance.view');
        Route::get('/purchases', [MaterialPurchaseController::class, 'index'])->middleware('permission:finance.view');
        Route::post('/purchases', [MaterialPurchaseController::class, 'store'])->middleware('permission:finance.manage');
        Route::get('/purchases/{purchase}', [MaterialPurchaseController::class, 'show'])->middleware('permission:finance.view');
        Route::put('/purchases/{purchase}', [MaterialPurchaseController::class, 'update'])->middleware('permission:finance.manage');
        Route::delete('/purchases/{purchase}', [MaterialPurchaseController::class, 'destroy'])->middleware('permission:finance.manage');
        Route::post('/purchases/{purchase}/payments', [MaterialPurchaseController::class, 'pay'])->middleware('permission:finance.manage');
    });

    // Renewals (Phase 7b).
    Route::middleware('module:renewals')->prefix('renewals')->group(function () {
        Route::get('/', [RenewalController::class, 'index'])->middleware('permission:renewals.view');
        Route::get('/calendar', [RenewalController::class, 'calendar'])->middleware('permission:renewals.view');
        Route::get('/costs', [RenewalController::class, 'costs'])->middleware('permission:renewals.view');
        Route::get('/import/template', [RenewalController::class, 'importTemplate'])->middleware('permission:renewals.manage');
        Route::post('/import/preview', [RenewalController::class, 'importPreview'])->middleware('permission:renewals.manage');
        Route::post('/import', [RenewalController::class, 'import'])->middleware('permission:renewals.manage');
        Route::post('/', [RenewalController::class, 'store'])->middleware('permission:renewals.manage');
        Route::get('/{renewal}', [RenewalController::class, 'show'])->middleware('permission:renewals.view');
        Route::put('/{renewal}', [RenewalController::class, 'update'])->middleware('permission:renewals.manage');
        Route::post('/{renewal}/active', [RenewalController::class, 'setActive'])->middleware('permission:renewals.manage');
        Route::post('/{renewal}/renew', [RenewalController::class, 'renew'])->middleware('permission:renewals.manage');
        Route::post('/{renewal}/payments', [RenewalController::class, 'pay'])->middleware('permission:renewals.manage');
        Route::post('/{renewal}/attachments', [RenewalController::class, 'attach'])->middleware('permission:renewals.manage');
        Route::get('/{renewal}/attachments/{attachment}', [RenewalController::class, 'attachment'])->middleware('permission:renewals.view');
    });

    // Vehicles (Phase 8a). Drivers reach their own vehicle without vehicles.view; the controller decides per vehicle.
    Route::middleware('module:vehicles')->group(function () {
        Route::get('/vehicles', [VehicleController::class, 'index']);
        Route::get('/vehicles/import/template', [VehicleController::class, 'importTemplate'])->middleware('permission:vehicles.manage');
        Route::post('/vehicles/import/preview', [VehicleController::class, 'importPreview'])->middleware('permission:vehicles.manage');
        Route::post('/vehicles/import', [VehicleController::class, 'import'])->middleware('permission:vehicles.manage');
        Route::post('/vehicles', [VehicleController::class, 'store'])->middleware('permission:vehicles.manage');
        Route::get('/vehicle-corrections', [VehicleCorrectionController::class, 'index']);
        Route::post('/vehicle-corrections/{correction}/review', [VehicleCorrectionController::class, 'review'])->middleware('permission:vehicles.manage');
        Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show']);
        Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update'])->middleware('permission:vehicles.manage');
        Route::post('/vehicles/{vehicle}/active', [VehicleController::class, 'setActive'])->middleware('permission:vehicles.manage');
        Route::get('/vehicles/{vehicle}/report', [VehicleController::class, 'report']);
        Route::post('/vehicles/{vehicle}/trips', [VehicleController::class, 'storeTrip']);
        Route::post('/vehicles/{vehicle}/fuel', [VehicleController::class, 'storeFuel']);
        Route::post('/vehicles/{vehicle}/services', [VehicleController::class, 'storeService']);
        Route::put('/vehicles/{vehicle}/entries/{type}/{entry}', [VehicleController::class, 'updateEntry'])->whereIn('type', ['trip', 'fuel', 'service'])->whereNumber('entry')->middleware('permission:vehicles.manage');
        Route::delete('/vehicles/{vehicle}/entries/{type}/{entry}', [VehicleController::class, 'destroyEntry'])->whereIn('type', ['trip', 'fuel', 'service'])->whereNumber('entry')->middleware('permission:vehicles.manage');
        Route::post('/vehicles/{vehicle}/entries/{type}/{entry}/corrections', [VehicleCorrectionController::class, 'store'])->whereIn('type', ['trip', 'fuel', 'service'])->whereNumber('entry');
    });

    // Maintenance register (Phase 8a).
    Route::middleware('module:maintenance')->prefix('maintenance')->group(function () {
        Route::get('/buildings', [MaintenanceController::class, 'buildings'])->middleware('permission:maintenance.view');
        Route::post('/buildings', [MaintenanceController::class, 'saveBuilding'])->middleware('permission:maintenance.manage');
        Route::put('/buildings/{building}', [MaintenanceController::class, 'saveBuilding'])->middleware('permission:maintenance.manage');
        Route::get('/locations', [MaintenanceController::class, 'locations'])->middleware('permission:maintenance.view');
        Route::post('/locations', [MaintenanceController::class, 'saveLocation'])->middleware('permission:maintenance.manage');
        Route::get('/locations/{location}', [MaintenanceController::class, 'showLocation'])->middleware('permission:maintenance.view');
        Route::put('/locations/{location}', [MaintenanceController::class, 'saveLocation'])->middleware('permission:maintenance.manage');
        Route::get('/logs', [MaintenanceController::class, 'logs'])->middleware('permission:maintenance.view');
        Route::post('/logs', [MaintenanceController::class, 'storeLog'])->middleware('permission:maintenance.log');
        Route::get('/logs/{log}', [MaintenanceController::class, 'showLog'])->middleware('permission:maintenance.view');
        Route::put('/logs/{log}', [MaintenanceController::class, 'updateLog'])->middleware('permission:maintenance.manage');
        Route::delete('/logs/{log}', [MaintenanceController::class, 'destroyLog'])->middleware('permission:maintenance.manage');
        Route::post('/logs/{log}/payments', [MaintenanceController::class, 'pay'])->middleware('permission:maintenance.manage');
        Route::get('/departments', [MaintenanceController::class, 'departments'])->middleware('permission:maintenance.manage');
        Route::put('/departments/{department}', [MaintenanceController::class, 'setDepartment'])->middleware('permission:maintenance.manage');
        Route::get('/report', [MaintenanceController::class, 'report'])->middleware('permission:maintenance.view');
    });

    // Work requests (Phase 8b). Anyone raises and follows their own; the controller decides per request.
    Route::middleware('module:requests')->group(function () {
        Route::get('/requests', [WorkRequestController::class, 'index']);
        Route::post('/requests', [WorkRequestController::class, 'store']);
        Route::get('/requests/report', [WorkRequestController::class, 'report'])->middleware('permission:requests.view');
        Route::get('/requests/departments', [WorkRequestController::class, 'departments']);
        Route::get('/requests/{workRequest}', [WorkRequestController::class, 'show']);
        Route::get('/requests/{workRequest}/assignees', [WorkRequestController::class, 'assignees']);
        Route::post('/requests/{workRequest}/status', [WorkRequestController::class, 'status']);
        Route::post('/requests/{workRequest}/assign', [WorkRequestController::class, 'assign']);
        Route::post('/requests/{workRequest}/comments', [WorkRequestController::class, 'comment']);
        Route::post('/requests/{workRequest}/cancel', [WorkRequestController::class, 'cancel']);
        Route::post('/requests/{workRequest}/close', [WorkRequestController::class, 'close']);
        Route::post('/requests/{workRequest}/reopen', [WorkRequestController::class, 'reopen']);
    });

    // Grievances (Phase 8b).
    Route::middleware('module:grievances')->group(function () {
        Route::get('/grievances', [GrievanceController::class, 'index']);
        Route::post('/grievances', [GrievanceController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/grievances/{grievance}', [GrievanceController::class, 'show']);
        Route::put('/grievances/{grievance}', [GrievanceController::class, 'update']);
        Route::post('/grievances/{grievance}/comments', [GrievanceController::class, 'comment']);
        Route::post('/grievances/{grievance}/reopen', [GrievanceController::class, 'reopen']);
    });

    // Hostels (Phase 8c). Hostel staff reach their own hostel without hostels.view; HostelAccess decides.
    Route::middleware('module:hostels')->group(function () {
        Route::get('/hostels', [HostelController::class, 'index']);
        Route::post('/hostels', [HostelController::class, 'store'])->middleware('permission:hostels.manage');
        Route::get('/hostels/residents/import/template', [HostelResidentController::class, 'importTemplate']);
        Route::get('/hostels/{hostel}', [HostelController::class, 'show']);
        Route::put('/hostels/{hostel}', [HostelController::class, 'update'])->middleware('permission:hostels.manage');
        Route::put('/hostels/{hostel}/staff', [HostelController::class, 'setStaff'])->middleware('permission:hostels.manage');
        Route::post('/hostels/{hostel}/rooms', [HostelController::class, 'saveRoom'])->middleware('permission:hostels.manage');
        Route::put('/hostels/{hostel}/rooms/{room}', [HostelController::class, 'saveRoom'])->middleware('permission:hostels.manage');
        Route::get('/hostels/{hostel}/residents', [HostelResidentController::class, 'index']);
        Route::post('/hostels/{hostel}/residents', [HostelResidentController::class, 'store']);
        Route::post('/hostels/{hostel}/residents/import/preview', [HostelResidentController::class, 'importPreview']);
        Route::post('/hostels/{hostel}/residents/import', [HostelResidentController::class, 'import']);
        Route::get('/hostels/{hostel}/attendance', [HostelAttendanceController::class, 'sheet']);
        Route::post('/hostels/{hostel}/attendance', [HostelAttendanceController::class, 'save']);
        Route::get('/hostel-residents/{resident}', [HostelResidentController::class, 'show']);
        Route::put('/hostel-residents/{resident}', [HostelResidentController::class, 'update']);
        Route::post('/hostel-residents/{resident}/allocate', [HostelResidentController::class, 'allocate']);
        Route::post('/hostel-residents/{resident}/vacate', [HostelResidentController::class, 'vacate']);
        Route::post('/hostel-residents/{resident}/out', [HostelResidentController::class, 'goOut']);
        Route::post('/hostel-residents/{resident}/returned', [HostelResidentController::class, 'returned']);
        Route::put('/hostel-residents/{resident}/health', [HostelResidentController::class, 'saveHealth']);
        Route::post('/hostel-residents/{resident}/incidents', [HostelResidentController::class, 'addIncident']);
        Route::post('/hostel-residents/{resident}/fees', [HostelResidentController::class, 'addFee']);
        Route::post('/hostel-fees/{fee}/payments', [HostelResidentController::class, 'payFee']);
        Route::post('/hostel-residents/{resident}/depart', [HostelResidentController::class, 'depart']);
        Route::put('/hostel-residents/{resident}/alumnus', [HostelResidentController::class, 'updateAlumnus']);
    });

    // Staff housing (Phase 8d). Everyone sees their own; residency.view / manage the rest.
    Route::middleware('module:residency')->group(function () {
        Route::get('/my/residency', [MyResidencyController::class, 'show']);
        Route::post('/my/residency/requests', [MyResidencyController::class, 'request']);
        Route::post('/my/residency/requests/{allotment}/cancel', [MyResidencyController::class, 'cancel']);
        Route::middleware('permission:residency.view')->prefix('residency')->group(function () {
            Route::get('/rooms', [ResidencyController::class, 'rooms']);
            Route::get('/rooms/{location}', [ResidencyController::class, 'room']);
            Route::get('/requests', [ResidencyController::class, 'requests']);
            Route::get('/bills', [ResidencyController::class, 'billsReport']);
            Route::get('/directory', [ResidencyController::class, 'directory']);
            Route::get('/analytics', [ResidencyController::class, 'analytics']);
            Route::get('/unlinked', [ResidencyController::class, 'unlinked']);
        });
        Route::middleware('permission:residency.manage')->prefix('residency')->group(function () {
            Route::post('/rooms/{location}/occupants', [ResidencyController::class, 'addOccupant']);
            Route::post('/rooms/{location}/items', [ResidencyController::class, 'addItem']);
            Route::delete('/items/{item}', [ResidencyController::class, 'removeItem']);
            Route::post('/rooms/{location}/inspections', [ResidencyController::class, 'inspect']);
            Route::post('/rooms/{location}/bills', [ResidencyController::class, 'createBill']);
            Route::post('/bill-shares/{share}/paid', [ResidencyController::class, 'markPaid']);
            Route::post('/rooms/{location}/guests', [ResidencyController::class, 'addGuest']);
            Route::post('/guests/{guest}/left', [ResidencyController::class, 'guestLeft']);
            Route::post('/occupants/{occupant}/vacate', [ResidencyController::class, 'vacate']);
            Route::post('/occupants/{occupant}/transfer', [ResidencyController::class, 'transfer']);
            Route::post('/occupants/{occupant}/link', [ResidencyController::class, 'link']);
            Route::post('/requests/{allotment}/decide', [ResidencyController::class, 'decide']);
            Route::get('/import/template', [ResidencyImportController::class, 'template']);
            Route::post('/import/preview', [ResidencyImportController::class, 'preview']);
            Route::post('/import', [ResidencyImportController::class, 'run']);
            Route::get('/import/history', [ResidencyImportController::class, 'history']);
            Route::post('/import/{batch}/rollback', [ResidencyImportController::class, 'rollback']);
        });
    });

    // Store and purchasing (Phase 9a).
    Route::middleware(['module:store', 'permission:store.view'])->prefix('store')->group(function () {
        Route::get('/items', [StoreController::class, 'items']);
        Route::get('/items/{item}', [StoreController::class, 'show']);
        Route::get('/issues', [StoreController::class, 'issuesReport']);
        Route::middleware('permission:store.manage')->group(function () {
            Route::post('/items', [StoreController::class, 'store']);
            Route::put('/items/{item}', [StoreController::class, 'update']);
            Route::post('/items/{item}/movements', [StoreController::class, 'move']);
        });
    });
    Route::middleware('module:purchasing')->prefix('purchasing')->group(function () {
        // The store sees orders to receive goods against them.
        Route::middleware('permission:purchasing.view|store.manage')->group(function () {
            Route::get('/orders', [PurchasingController::class, 'orders']);
            Route::get('/orders/{order}', [PurchasingController::class, 'showOrder']);
            Route::post('/orders/{order}/receive', [PurchasingController::class, 'receive']);
        });
        Route::middleware('permission:purchasing.view')->group(function () {
            Route::get('/vendors', [VendorController::class, 'index']);
            Route::get('/quotations', [PurchasingController::class, 'quotations']);
        });
        Route::middleware('permission:purchasing.manage')->group(function () {
            Route::post('/vendors', [VendorController::class, 'store']);
            Route::put('/vendors/{vendor}', [VendorController::class, 'update']);
            Route::post('/quotations', [PurchasingController::class, 'storeQuotation']);
            Route::post('/quotations/{quotation}/decide', [PurchasingController::class, 'decideQuotation']);
            Route::post('/orders', [PurchasingController::class, 'storeOrder']);
            Route::put('/orders/{order}', [PurchasingController::class, 'updateOrder']);
            Route::post('/orders/{order}/issue', [PurchasingController::class, 'issueOrder']);
            Route::post('/orders/{order}/cancel', [PurchasingController::class, 'cancelOrder']);
            Route::post('/orders/{order}/invoices', [PurchasingController::class, 'storeInvoice']);
            Route::delete('/invoices/{invoice}', [PurchasingController::class, 'destroyInvoice']);
            Route::post('/invoices/{invoice}/payments', [PurchasingController::class, 'payInvoice']);
        });
    });

    // Dead stock and the issue / return register (Phase 9b).
    Route::middleware('module:assets')->group(function () {
        Route::get('/my/assets', [AllocationController::class, 'mine']);
        Route::middleware('permission:assets.view')->prefix('assets')->group(function () {
            Route::get('/masters', [DeadStockController::class, 'masters']);
            Route::get('/dead-stock', [DeadStockController::class, 'index']);
            Route::get('/dead-stock/report', [DeadStockController::class, 'report']);
            Route::get('/allocation-types', [AllocationController::class, 'types']);
            Route::get('/allocation-items', [AllocationController::class, 'items']);
            Route::get('/allocations', [AllocationController::class, 'index']);
            Route::get('/allocations/report', [AllocationController::class, 'report']);
        });
        Route::middleware('permission:assets.manage')->prefix('assets')->group(function () {
            Route::post('/masters', [DeadStockController::class, 'storeMaster']);
            Route::put('/masters/{master}', [DeadStockController::class, 'updateMaster']);
            Route::get('/masters/import/template', [DeadStockController::class, 'masterTemplate']);
            Route::post('/masters/import/preview', [DeadStockController::class, 'masterPreview']);
            Route::post('/masters/import', [DeadStockController::class, 'masterImport']);
            Route::post('/dead-stock', [DeadStockController::class, 'store']);
            Route::put('/dead-stock/{item}', [DeadStockController::class, 'update']);
            Route::delete('/dead-stock/{item}', [DeadStockController::class, 'destroy']);
            Route::post('/allocation-types', [AllocationController::class, 'storeType']);
            Route::put('/allocation-types/{type}', [AllocationController::class, 'updateType']);
            Route::post('/allocation-items', [AllocationController::class, 'storeItem']);
            Route::post('/allocation-items/{item}/retire', [AllocationController::class, 'retireItem']);
            Route::post('/allocations', [AllocationController::class, 'allocate']);
            Route::post('/allocations/{allocation}/return', [AllocationController::class, 'giveBack']);
            Route::post('/allocations/{allocation}/transfer', [AllocationController::class, 'transfer']);
            Route::get('/allocations/import/template', [AllocationController::class, 'importTemplate']);
            Route::post('/allocations/import/preview', [AllocationController::class, 'importPreview']);
            Route::post('/allocations/import', [AllocationController::class, 'import']);
        });
    });

    // The IT department (Phase 9c). Lab assistants reach their own labs; the controller decides.
    Route::middleware('module:it')->group(function () {
        Route::get('/it/lookup', [ItController::class, 'lookup'])->middleware('throttle:60,1');
        Route::post('/it/assets/{asset}/report', [ItController::class, 'report'])->middleware('throttle:20,1');
        Route::get('/my/it-tickets', [ItController::class, 'mine']);
        Route::get('/it/dashboard', [ItController::class, 'dashboard']);
        Route::get('/it/labs', [ItController::class, 'labs']);
        Route::get('/it/assets', [ItController::class, 'assets']);
        Route::get('/it/assets/{asset}', [ItController::class, 'show']);
        Route::post('/it/assets', [ItController::class, 'storeAsset']);
        Route::put('/it/assets/{asset}', [ItController::class, 'updateAsset']);
        Route::post('/it/assets/{asset}/status', [ItController::class, 'setStatus']);
        Route::get('/it/tickets', [ItController::class, 'tickets']);
        Route::get('/it/smartboards', [ItController::class, 'smartboards']);
        Route::middleware('permission:it.manage')->group(function () {
            Route::post('/it/labs', [ItController::class, 'storeLab']);
            Route::put('/it/labs/{lab}', [ItController::class, 'updateLab']);
            Route::post('/it/assets/{asset}/transfer', [ItController::class, 'transfer']);
            Route::post('/it/tickets/{ticket}/start', [ItController::class, 'startTicket']);
            Route::post('/it/tickets/{ticket}/resolve', [ItController::class, 'resolveTicket']);
            Route::get('/it/import/template', [ItController::class, 'importTemplate']);
            Route::post('/it/import/preview', [ItController::class, 'importPreview']);
            Route::post('/it/import', [ItController::class, 'import']);
        });
    });

    // CCTV (Phase 9d).
    Route::middleware('module:cctv')->prefix('cctv')->group(function () {
        Route::middleware('permission:cctv.view|cctv.check|cctv.manage')->group(function () {
            Route::get('/dashboard', [CctvController::class, 'dashboard']);
            Route::get('/dvrs/{dvr}', [CctvController::class, 'show']);
            Route::get('/dvrs/{dvr}/checkup', [CctvController::class, 'sheet']);
            Route::get('/report', [CctvController::class, 'report']);
        });
        Route::post('/dvrs/{dvr}/checkup', [CctvController::class, 'saveCheckup'])->middleware('permission:cctv.check|cctv.manage');
        Route::middleware('permission:cctv.manage')->group(function () {
            Route::post('/dvrs', [CctvController::class, 'storeDvr']);
            Route::put('/dvrs/{dvr}', [CctvController::class, 'updateDvr']);
            Route::post('/dvrs/{dvr}/cameras', [CctvController::class, 'storeCamera']);
            Route::put('/cameras/{camera}', [CctvController::class, 'updateCamera']);
            Route::get('/import/template', [CctvController::class, 'importTemplate']);
            Route::post('/import/preview', [CctvController::class, 'importPreview']);
            Route::post('/import', [CctvController::class, 'import']);
        });
    });

    // Correspondence (Phase 6).
    Route::middleware('module:letters')->group(function () {
        Route::get('/letters', [LetterController::class, 'index']);
        Route::get('/letters/options', [LetterController::class, 'options']);
        Route::get('/letters/recipients', [LetterController::class, 'recipients'])->middleware('throttle:60,1');
        Route::get('/letters/register', LetterRegisterController::class)->middleware('permission:letters.view');
        Route::post('/letters', [LetterController::class, 'store']);
        Route::get('/letters/{letter}', [LetterController::class, 'show']);
        Route::put('/letters/{letter}', [LetterController::class, 'update']);
        Route::post('/letters/{letter}/{action}', [LetterController::class, 'send'])->whereIn('action', ['submit', 'forward']);
        Route::post('/letters/{letter}/return', [LetterController::class, 'sendBack']);
        Route::post('/letters/{letter}/remarks', [LetterController::class, 'remark']);
        Route::post('/letters/{letter}/reassign', [LetterController::class, 'reassign']);
        Route::post('/letters/{letter}/dispatch', [LetterController::class, 'recordDispatch']);
        Route::post('/letters/{letter}/sign', [LetterController::class, 'sign'])->middleware('throttle:10,1');
        Route::post('/letters/{letter}/attachments', [LetterController::class, 'attach']);
        Route::get('/letters/{letter}/attachments/{attachment}', [LetterController::class, 'attachment']);

        Route::middleware('permission:letters.manage')->prefix('letter-settings')->group(function () {
            Route::get('/categories', [LetterSettingsController::class, 'categories']);
            Route::post('/categories', [LetterSettingsController::class, 'storeCategory']);
            Route::post('/categories/defaults', [LetterSettingsController::class, 'seedCategories']);
            Route::put('/categories/{category}', [LetterSettingsController::class, 'updateCategory']);
            Route::get('/workflows', [LetterSettingsController::class, 'workflows']);
            Route::post('/workflows', [LetterSettingsController::class, 'storeWorkflow']);
            Route::put('/workflows/{workflow}', [LetterSettingsController::class, 'updateWorkflow']);
        });
    });

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
