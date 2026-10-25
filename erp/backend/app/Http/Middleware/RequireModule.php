<?php

namespace App\Http\Middleware;

use App\Support\Access\AccessContext;
use App\Support\Access\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route guard for self-service routes of a module the organization may switch off: `->middleware('module:tasks')`. */
class RequireModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (in_array($module, Modules::disabled(AccessContext::current()?->organization()), true)) {
            abort(403, __('access.module_disabled'));
        }

        return $next($request);
    }
}
