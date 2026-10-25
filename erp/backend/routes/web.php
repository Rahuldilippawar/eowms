<?php

use Illuminate\Support\Facades\Route;

// The React SPA (built by erp/frontend into public/app) handles every
// non-API path; React Router decides what to render.
Route::get('/{any?}', function () {
    $index = public_path('app/index.html');

    abort_unless(is_file($index), 503, 'Frontend build not found. Run the frontend build first.');

    return response()->file($index, [
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
})->where('any', '^(?!api/|sanctum/|up$).*$')->name('spa');
