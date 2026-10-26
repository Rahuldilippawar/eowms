<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\BudgetTransaction;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $works;

    private Department $physics;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->works = Department::factory()->for($this->org)->create(['code' => 'MNT']);
        $this->physics = Department::factory()->for($this->org)->create(['code' => 'PHY']);
        $this->admin = $this->userWithRole($this->org, ['maintenance.view', 'maintenance.log', 'maintenance.manage', 'maintenance.export']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 10:00', 'Asia/Kolkata'));
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-10 10:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_register_departments_locations_logs_payments_and_report(): void
    {
        $this->actingAs($this->admin);
        $building = $this->spa()->postJson('/api/v1/maintenance/buildings', ['name' => 'Main Building'])->assertCreated()->json('data.id');
        $this->spa()->postJson('/api/v1/maintenance/buildings', ['name' => 'Main Building'])->assertStatus(422);
        $lab = $this->spa()->postJson('/api/v1/maintenance/locations', ['name' => 'Physics Lab', 'location_code' => 'MB-101', 'building_id' => $building, 'floor' => 1])
            ->assertCreated()->assertJsonPath('data.building.name', 'Main Building')->json('data.id');

        // Only departments that keep a register can log.
        $entry = ['location_id' => $lab, 'department_id' => $this->works->id, 'item_description' => 'Ceiling fan', 'work_type' => 'repaired', 'work_date' => '2026-06-09', 'cost' => 1200];
        $this->spa()->postJson('/api/v1/maintenance/logs', $entry)->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->spa()->putJson("/api/v1/maintenance/departments/{$this->works->id}", ['has_maintenance_register' => true])->assertOk();
        $this->spa()->getJson('/api/v1/maintenance/locations')->assertJsonPath('meta.log_departments.0.id', $this->works->id);
        $log = $this->spa()->postJson('/api/v1/maintenance/logs', $entry)->assertCreated()->assertJsonPath('data.payment_status', 'pending')->json('data.id');
        $this->spa()->postJson('/api/v1/maintenance/logs', [...$entry, 'work_date' => '2026-06-11'])->assertStatus(422)->assertJsonValidationErrors('work_date');
        $this->assertSame(['1200.00', 'maintenance'], [BudgetTransaction::query()->where('source_type', 'maintenance_log')->value('amount'), BudgetTransaction::query()->value('category')]);

        // Payments never exceed the cost; a paid log cannot be deleted or cut below what was paid.
        $this->spa()->postJson("/api/v1/maintenance/logs/{$log}/payments", ['amount' => 1500, 'payment_date' => '2026-06-10', 'payment_type' => 'final'])->assertStatus(422);
        $this->spa()->postJson("/api/v1/maintenance/logs/{$log}/payments", ['amount' => 700, 'payment_date' => '2026-06-10', 'payment_type' => 'advance'])
            ->assertOk()->assertJsonPath('data.payment_status', 'partial')->assertJsonPath('data.due_amount', 500);
        $this->spa()->putJson("/api/v1/maintenance/logs/{$log}", [...$entry, 'cost' => 600])->assertStatus(422)->assertJsonValidationErrors('cost');
        $this->spa()->deleteJson("/api/v1/maintenance/logs/{$log}")->assertStatus(422);

        $this->spa()->getJson('/api/v1/maintenance/locations')->assertJsonPath('data.0.total_logs', 1)->assertJsonPath('data.0.recent_issues', 1)->assertJsonPath('data.0.last_work_date', '2026-06-09');
        $this->spa()->getJson("/api/v1/maintenance/locations/{$lab}")->assertJsonPath('data.total_cost', 1200)->assertJsonCount(1, 'data.logs');
        $this->spa()->getJson('/api/v1/maintenance/report?from=2026-04-01&to=2026-06-30')->assertOk()
            ->assertJsonPath('data.by_department.0.cost', 1200)->assertJsonPath('data.by_department.0.paid', 700)->assertJsonPath('data.by_work_type.0.work_type', 'repaired');
        $this->assertStringContainsString('Ceiling fan', $this->spa()->get('/api/v1/maintenance/logs?format=csv')->streamedContent());
    }

    public function test_department_scope_limits_what_is_seen_and_logged(): void
    {
        $this->works->forceFill(['has_maintenance_register' => true])->save();
        $this->physics->forceFill(['has_maintenance_register' => true])->save();
        $this->actingAs($this->admin);
        $lab = $this->spa()->postJson('/api/v1/maintenance/locations', ['name' => 'Lab', 'location_code' => 'L-1'])->json('data.id');
        $this->spa()->postJson('/api/v1/maintenance/logs', ['location_id' => $lab, 'department_id' => $this->works->id, 'item_description' => 'Tap', 'work_type' => 'replaced', 'work_date' => '2026-06-10'])->assertCreated();

        $plumber = $this->userWithRole($this->org, ['maintenance.view', 'maintenance.log'], PermissionScope::Departments, departmentIds: [$this->physics->id]);
        $this->actingAs($plumber);
        $this->spa()->getJson('/api/v1/maintenance/logs')->assertJsonCount(0, 'data');
        $this->spa()->getJson('/api/v1/maintenance/locations')->assertJsonPath('data.0.total_logs', 0)->assertJsonCount(1, 'meta.log_departments');
        $this->spa()->postJson('/api/v1/maintenance/logs', ['location_id' => $lab, 'department_id' => $this->works->id, 'item_description' => 'Tap', 'work_type' => 'replaced', 'work_date' => '2026-06-10'])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->spa()->postJson('/api/v1/maintenance/locations', ['name' => 'X', 'location_code' => 'X-1'])->assertForbidden();
        $this->spa()->get('/api/v1/maintenance/logs?format=csv')->assertForbidden();
    }
}
