<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_login_logout_failed_login_and_switch_are_recorded_without_secrets(): void
    {
        $this->setUpPermissions();
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $user = $this->userWithRole($org, ['departments.view'], user: User::factory()->create(['password' => 'secret-pass']));
        $this->userWithRole($other, ['departments.view'], user: $user);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertOk();
        $this->spa()->postJson('/api/v1/auth/switch-organization', ['organization_id' => $other->id])->assertOk();
        $this->spa()->postJson('/api/v1/auth/logout')->assertNoContent();

        $actions = AuditLog::where('user_id', $user->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['login_failed', 'login', 'switch_organization', 'logout'], $actions);

        $all = AuditLog::all()->toJson();
        $this->assertStringNotContainsString('secret-pass', $all);
        $this->assertStringNotContainsString('wrong', $all);
    }

    public function test_audit_rows_cannot_be_changed_or_deleted(): void
    {
        $log = AuditLog::create(['module' => 'auth', 'action' => 'login']);

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_audit_rows_cannot_be_deleted(): void
    {
        $log = AuditLog::create(['module' => 'auth', 'action' => 'login']);

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
