<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_request_answers_the_same_for_known_unknown_and_inactive_emails(): void
    {
        Notification::fake();
        $active = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        $messages = collect([$active->email, 'nobody@example.com', $inactive->email])
            ->map(fn ($email) => $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertOk()->json('message'));

        $this->assertCount(1, $messages->unique());
        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
    }

    public function test_reset_link_points_to_the_spa(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, rtrim(config('app.url'), '/').'/reset-password?token=')
                && str_contains($url, 'email='.urlencode($user->email));
        });
    }

    public function test_user_can_reset_and_old_sessions_end(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => 'old-pass-1']);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'new-pass-22', 'password_confirmation' => 'new-pass-22',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-pass-22', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertTrue(AuditLog::where(['user_id' => $user->id, 'action' => 'password_reset'])->exists());

        // The same token cannot be used twice.
        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'another-pass-3', 'password_confirmation' => 'another-pass-3',
        ])->assertStatus(422);
    }

    public function test_bad_token_unknown_email_and_weak_password_are_rejected(): void
    {
        $user = User::factory()->create();

        $badToken = $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => 'nope', 'email' => $user->email, 'password' => 'new-pass-22', 'password_confirmation' => 'new-pass-22',
        ])->assertStatus(422)->json('errors.email.0');

        $unknown = $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => 'nope', 'email' => 'nobody@example.com', 'password' => 'new-pass-22', 'password_confirmation' => 'new-pass-22',
        ])->assertStatus(422)->json('errors.email.0');

        $this->assertSame($badToken, $unknown);

        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => 'x', 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_requests_are_rate_limited(): void
    {
        Notification::fake();
        for ($i = 0; $i < 3; $i++) {
            $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => 'a@example.com'])->assertOk();
        }
        $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => 'a@example.com'])->assertStatus(429);
    }
}
