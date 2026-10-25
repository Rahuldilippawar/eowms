<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** API messages follow the SPA's language (sent as Accept-Language: mr | en). */
class SetLocaleFromRequest
{
    private const SUPPORTED = ['mr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $preferred = $request->getPreferredLanguage(self::SUPPORTED);
        if ($request->headers->has('Accept-Language') && in_array($preferred, self::SUPPORTED, true)) {
            app()->setLocale($preferred);
        }

        return $next($request);
    }
}
