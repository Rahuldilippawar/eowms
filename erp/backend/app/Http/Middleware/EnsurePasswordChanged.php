<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts flagged `must_change_password` (imported or reset by an admin)
 * can only read who they are, change the password or sign out until they
 * set their own password.
 */
class EnsurePasswordChanged
{
    private const ALLOWED = ['api.auth.me', 'api.auth.logout', 'api.auth.change-password'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return response()->json(['message' => __('access.password_change_required'), 'code' => 'password_change_required'], 403);
        }

        return $next($request);
    }
}
