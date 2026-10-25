<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** The SPA's origin, so Sanctum treats requests as first-party (stateful). */
    private function spa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
    }

    /** A real next request starts with fresh guards; the test client reuses them. */
    private function forgetGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_user_can_log_in_and_read_their_profile(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->spa()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_wrong_password_unknown_email_and_inactive_get_the_same_error(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);
        $inactive = User::factory()->inactive()->create(['password' => 'secret-pass']);

        $cases = [
            ['email' => $user->email, 'password' => 'wrong'],
            ['email' => 'nobody@example.com', 'password' => 'secret-pass'],
            ['email' => $inactive->email, 'password' => 'secret-pass'],
        ];

        $messages = [];
        foreach ($cases as $case) {
            $messages[] = $this->spa()->postJson('/api/v1/auth/login', $case)
                ->assertStatus(422)
                ->assertJsonValidationErrors('email')
                ->json('errors.email.0');
        }

        $this->assertCount(1, array_unique($messages));
        $this->assertGuest('web');
    }

    public function test_login_requires_valid_input(): void
    {
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        for ($i = 0; $i < 5; $i++) {
            $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
        }

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertStatus(429);
        $this->assertGuest('web');
    }

    public function test_protected_routes_reject_guests(): void
    {
        $this->spa()->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->spa()->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertOk();

        $this->spa()->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
        $this->forgetGuards();
        $this->spa()->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_user_deactivated_mid_session_is_signed_out(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertOk();

        $user->forceFill(['is_active' => false])->save();
        $this->forgetGuards();

        $this->spa()->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_api_errors_are_json_without_debug_details(): void
    {
        config(['app.debug' => false]);

        $this->get('/api/v1/does-not-exist', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('exception');
    }
}
