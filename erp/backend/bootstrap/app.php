<?php

use App\Http\Middleware\RequireModule;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SetAccessContext;
use App\Http\Middleware\SetLocaleFromRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First-party SPA: Sanctum session-cookie auth + CSRF on the API.
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->api(append: [SetLocaleFromRequest::class]);
        $middleware->alias(['permission' => RequirePermission::class, 'module' => RequireModule::class]);
        // The access context must exist before route-model binding runs, so
        // bound models are already limited to the current organization.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetAccessContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API callers always get JSON errors without stack traces.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
