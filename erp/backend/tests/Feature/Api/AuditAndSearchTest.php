<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\StoreItem;
use App\Models\Vehicle;
use App\Support\Access\AccessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class AuditAndSearchTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Organization $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
    }

    public function test_activity_and_sign_in_log_stay_within_the_organization(): void
    {
        $member = $this->userWithRole($this->org, ['departments.view']);
        $outsider = $this->userWithRole($this->other, ['departments.view']);
        foreach ([[$member, 'password'], [$member, 'wrong-pass'], [$outsider, 'password']] as [$user, $password]) {
            $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password]);
            // Each request starts without an organization, as in production.
            $this->app['auth']->forgetGuards();
            AccessContext::set(null);
        }
        AuditLog::create(['organization_id' => $this->org->id, 'user_id' => $member->id, 'module' => 'letters', 'action' => 'create', 'auditable_type' => 'App\\Models\\Letter', 'auditable_id' => 7, 'new_values' => ['subject' => 'Fees']]);
        AuditLog::create(['organization_id' => $this->other->id, 'user_id' => $outsider->id, 'module' => 'letters', 'action' => 'create']);

        // Department-wide audit.view is not enough: the log spans every department.
        $dept = Department::factory()->for($this->org)->create();
        $this->actingAs($this->userWithRole($this->org, ['audit.view'], PermissionScope::Departments, departmentIds: [$dept->id]));
        $this->spa()->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->actingAs($this->userWithRole($this->org, ['departments.view']));
        $this->spa()->getJson('/api/v1/audit-logs/logins')->assertForbidden();

        $auditor = $this->userWithRole($this->org, ['audit.view']);
        $this->actingAs($auditor);
        $rows = $this->spa()->getJson('/api/v1/audit-logs?module=letters')->assertOk()->assertJsonPath('meta.can_export', false)->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame(['Letter', 7, ['subject' => 'Fees']], [$rows[0]['subject']['type'], $rows[0]['subject']['id'], $rows[0]['new_values']]);
        $this->spa()->getJson('/api/v1/audit-logs?module=letters&from=2000-01-01&to=2000-01-02')->assertOk()->assertJsonCount(0, 'data');
        $this->spa()->getJson('/api/v1/audit-logs?format=csv')->assertForbidden();

        $logins = $this->spa()->getJson('/api/v1/audit-logs/logins')->assertOk()
            ->assertJsonPath('meta.summary.logins_7d', 1)->assertJsonPath('meta.summary.failed_7d', 1)->json('data');
        $this->assertEqualsCanonicalizing(['login', 'login_failed'], array_column($logins, 'action'));
        $this->assertSame([$member->id], array_values(array_unique(array_column(array_column($logins, 'user'), 'id'))));
        $this->assertNotContains($outsider->id, array_column($this->spa()->getJson('/api/v1/audit-logs/users')->assertOk()->json('data'), 'id'));

        $this->actingAs($this->userWithRole($this->org, ['audit.view', 'audit.export']));
        $csv = $this->spa()->get('/api/v1/audit-logs?format=csv&module=letters')->assertOk()->streamedContent();
        $this->assertStringContainsString('Letter', $csv);
        $this->assertTrue(AuditLog::query()->where(['module' => 'audit', 'action' => 'activity_export'])->exists());
    }

    public function test_search_follows_each_modules_visibility(): void
    {
        $physics = Department::factory()->for($this->org)->create();
        $chemistry = Department::factory()->for($this->org)->create();
        Employee::factory()->in($physics)->create(['name' => 'Asha Patil']);
        Employee::factory()->in($chemistry)->create(['name' => 'Asha Kulkarni']);
        Employee::factory()->in(Department::factory()->for($this->other)->create())->create(['name' => 'Asha Other']);
        StoreItem::query()->forceCreate(['organization_id' => $this->org->id, 'item_name' => 'Asha cement', 'unit' => 'bag']);
        $driver = $this->userWithRole($this->org, ['departments.view']);
        Vehicle::query()->forceCreate(['organization_id' => $this->org->id, 'department_id' => $physics->id, 'vehicle_number' => 'MH12ASHA1', 'vehicle_type' => 'Bus', 'fuel_type' => 'diesel', 'driver_user_id' => $driver->id, 'created_by' => $driver->id]);

        $this->actingAs($this->userWithRole($this->org, ['employees.view', 'store.view'], PermissionScope::Departments, departmentIds: [$physics->id]));
        $this->spa()->getJson('/api/v1/search?q=a')->assertStatus(422);
        $data = $this->spa()->getJson('/api/v1/search?q=asha')->assertOk()->json('data');
        $this->assertSame(['Asha Patil'], array_column($data['employees'], 'title'));
        $this->assertSame(['Asha cement'], array_column($data['store'], 'title'));
        $this->assertArrayNotHasKey('vehicles', $data);

        // A driver finds only their own vehicle; nothing else without permissions.
        $this->actingAs($driver);
        $data = $this->spa()->getJson('/api/v1/search?q=asha')->assertOk()->json('data');
        $this->assertSame(['vehicles'], array_keys($data));
        $this->assertStringStartsWith('/vehicles/', $data['vehicles'][0]['url']);

        // A switched-off module is not searched.
        $this->org->forceFill(['settings' => ['modules_disabled' => ['vehicles', 'store']]])->save();
        $this->assertSame([], $this->spa()->getJson('/api/v1/search?q=asha')->assertOk()->json('data'));
    }
}
