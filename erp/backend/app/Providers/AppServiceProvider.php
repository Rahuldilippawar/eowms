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
        // General API limit: 120 requests/minute per user (or IP for guests) by default (config/auth.php).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(config('auth.api_per_minute'))->by($request->user()?->id ?: $request->ip());
        });

        // Password-reset requests per minute per email+IP and per IP (config/auth.php).
        RateLimiter::for('password-reset', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(config('auth.password_reset_throttle.per_email'))->by('pr|'.$email.'|'.$request->ip()),
                Limit::perMinute(config('auth.password_reset_throttle.per_ip'))->by('pr|'.$request->ip()),
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

        // Fingerprint sign-in carries no e-mail: limited per key and per IP. Each sign-in is two
        // requests (challenge + answer), and a whole office may share one IP.
        RateLimiter::for('webauthn-login', function (Request $request) {
            $key = (string) $request->input('credential.id');

            return array_filter([
                $key !== '' ? Limit::perMinute(config('auth.login_throttle.per_email'))->by('wa|'.$key.'|'.$request->ip()) : null,
                Limit::perMinute(config('auth.login_throttle.per_ip') * 2)->by('wa|'.$request->ip()),
            ]);
        });
    }
}
