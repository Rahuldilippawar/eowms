<?php

namespace App\Http\Middleware;

use App\Support\Access\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route guard: `->middleware('permission:institutions.view')`; `a|b` passes with either. */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $context = AccessContext::current();
        $any = collect(explode('|', $permission))->contains(fn (string $p) => $context?->can($p) ?? false);
        if (! $any) {
            abort(403, __('auth.forbidden'));
        }

        return $next($request);
    }
}
