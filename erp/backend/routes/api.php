<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
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
});
