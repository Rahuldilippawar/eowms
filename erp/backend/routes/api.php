<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\PasswordResetController;
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

    Route::get('/institutions', [InstitutionController::class, 'index'])->middleware('permission:institutions.view');
    Route::get('/institutions/{institution}', [InstitutionController::class, 'show'])->middleware('permission:institutions.view');

    Route::get('/departments', [DepartmentController::class, 'index'])->middleware('permission:departments.view');
    Route::get('/departments/{department}', [DepartmentController::class, 'show'])->middleware('permission:departments.view');
});
