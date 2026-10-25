<?php

namespace App\Http\Middleware;

use App\Support\Access\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before route-model binding on every authenticated API route:
 * signs out a user who was deactivated, then resolves their current
 * organization (kept in the session, re-validated on every request) and
 * makes it the request's AccessContext.
 */
class SetAccessContext
{
    public const SESSION_KEY = 'current_organization_id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }
        if (! $user->is_active) {
            return (new EnsureUserIsActive)->handle($request, $next);
        }

        $organizations = AccessContext::organizationsFor($user);
        $wanted = (int) $request->session()->get(self::SESSION_KEY);
        $organization = $organizations->firstWhere('id', $wanted) ?? $organizations->first();

        if ($organization?->id !== $wanted) {
            $organization
                ? $request->session()->put(self::SESSION_KEY, $organization->id)
                : $request->session()->forget(self::SESSION_KEY);
        }

        AccessContext::set(new AccessContext($user, $organization));

        try {
            return $next($request);
        } finally {
            AccessContext::set(null);
        }
    }
}
