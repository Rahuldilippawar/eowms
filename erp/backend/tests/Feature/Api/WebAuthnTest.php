<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;

class WebAuthnTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        config(['app.url' => 'http://localhost', 'services.webauthn.rp_id' => '', 'services.webauthn.origins' => '']);
        $this->user = $this->userWithRole(Organization::factory()->create(), ['departments.view']);
    }

    private function register(FakeAuthenticator $device, string $password = 'password', bool $verified = true): TestResponse
    {
        $this->actingAs($this->user);
        $options = $this->spa()->postJson('/api/v1/me/webauthn/register/options')->assertOk()->json('data');

        return $this->spa()->postJson('/api/v1/me/webauthn/register', ['password' => $password, 'name' => 'My phone', 'credential' => $device->create($options, $verified)]);
    }

    public function test_adding_a_key_needs_the_password_and_a_verified_fresh_answer(): void
    {
        // This test makes more attempts than a person would in a minute; throttling is not under test here.
        $this->withoutMiddleware(ThrottleRequests::class);
        $device = new FakeAuthenticator;
        $this->register($device, 'wrong')->assertStatus(422)->assertJsonValidationErrors('password');
        $this->register($device, verified: false)->assertStatus(422)->assertJsonValidationErrors('credential');
        $this->register($device)->assertCreated()->assertJsonPath('data.0.name', 'My phone')->assertJsonMissingPath('data.0.public_key');
        $this->register($device)->assertStatus(422);

        // A replayed answer: its challenge was used up.
        $options = $this->spa()->postJson('/api/v1/me/webauthn/register/options')->json('data');
        $answer = (new FakeAuthenticator)->create($options);
        $this->spa()->postJson('/api/v1/me/webauthn/register', ['password' => 'password', 'name' => 'Tablet', 'credential' => $answer])->assertCreated();
        $this->spa()->postJson('/api/v1/me/webauthn/register', ['password' => 'password', 'name' => 'Tablet', 'credential' => $answer])->assertStatus(422);
        $this->assertSame(2, WebauthnCredential::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'webauthn_added')->exists());
    }

    public function test_signing_in_with_a_key(): void
    {
        $device = new FakeAuthenticator;
        $this->register($device)->assertCreated();
        $this->spa()->postJson('/api/v1/auth/logout')->assertNoContent();
        auth()->forgetGuards();

        $options = $this->spa()->postJson('/api/v1/auth/webauthn/options')->assertOk()->assertJsonPath('data.rpId', 'localhost')->json('data');
        $this->spa()->postJson('/api/v1/auth/webauthn/login', ['credential' => $device->get($options, 'https://evil.example')])->assertStatus(422);

        $options = $this->spa()->postJson('/api/v1/auth/webauthn/options')->json('data');
        $answer = $device->get($options);
        $this->spa()->postJson('/api/v1/auth/webauthn/login', ['credential' => $answer])->assertOk()->assertJsonPath('data.id', $this->user->id);
        $this->assertTrue(AuditLog::query()->where(['action' => 'login', 'user_id' => $this->user->id])->whereJsonContains('new_values->method', 'webauthn')->exists());

        // The same answer again (new challenge, old counter) fails; so does an inactive account.
        $this->spa()->postJson('/api/v1/auth/webauthn/options');
        $this->spa()->postJson('/api/v1/auth/webauthn/login', ['credential' => $answer])->assertStatus(422);
        $this->user->forceFill(['is_active' => false])->save();
        $options = $this->spa()->postJson('/api/v1/auth/webauthn/options')->json('data');
        $this->spa()->postJson('/api/v1/auth/webauthn/login', ['credential' => $device->get($options)])->assertStatus(422);
    }

    public function test_a_key_can_be_removed_only_by_its_owner(): void
    {
        $this->register(new FakeAuthenticator)->assertCreated();
        $id = WebauthnCredential::query()->value('id');
        $this->actingAs($this->userWithRole(Organization::factory()->create(), ['departments.view']));
        $this->spa()->deleteJson("/api/v1/me/webauthn/{$id}")->assertNotFound();
        $this->actingAs($this->user);
        $this->spa()->deleteJson("/api/v1/me/webauthn/{$id}")->assertOk()->assertJsonCount(0, 'data');
    }
}
