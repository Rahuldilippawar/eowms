<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // General API limit: 120 requests/minute per user (or IP for guests).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Password-reset requests: 3 per minute per email+IP, 10 per IP.
        RateLimiter::for('password-reset', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(3)->by('pr|'.$email.'|'.$request->ip()),
                Limit::perMinute(10)->by('pr|'.$request->ip()),
            ];
        });

        // The emailed link opens the SPA's reset page, not a Laravel view.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return rtrim((string) config('app.url'), '/').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });

        // Login attempts per minute per email+IP, and per IP overall (config/auth.php).
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(config('auth.login_throttle.per_email'))->by($email.'|'.$request->ip()),
                Limit::perMinute(config('auth.login_throttle.per_ip'))->by($request->ip()),
            ];
        });
    }
}
