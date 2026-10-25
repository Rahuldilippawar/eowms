<?php

namespace App\Http\Middleware;

use App\Support\Access\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route guard: `->middleware('permission:institutions.view')`. */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! AccessContext::current()?->can($permission)) {
            abort(403, __('auth.forbidden'));
        }

        return $next($request);
    }
}
