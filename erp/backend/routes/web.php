<?php

use App\Http\Controllers\IclockController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// ZKTeco attendance machines (ADMS push). Machines cannot send CSRF tokens or cookies.
Route::withoutMiddleware([ValidateCsrfToken::class, StartSession::class, ShareErrorsFromSession::class, AddQueuedCookiesToResponse::class, EncryptCookies::class])
    ->middleware('throttle:240,1')->prefix('iclock')->group(function () {
        Route::match(['get', 'post'], '/cdata', [IclockController::class, 'cdata']);
        Route::get('/getrequest', [IclockController::class, 'getrequest']);
        Route::post('/devicecmd', [IclockController::class, 'devicecmd']);
    });

// The React SPA (built by erp/frontend into public/app) handles every
// non-API path; React Router decides what to render.
Route::get('/{any?}', function () {
    $index = public_path('app/index.html');

    abort_unless(is_file($index), 503, 'Frontend build not found. Run the frontend build first.');

    return response()->file($index, [
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
})->where('any', '^(?!api/|sanctum/|iclock/|up$).*$')->name('spa');
