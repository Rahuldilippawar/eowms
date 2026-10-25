<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Institution;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Attempts to reach another organization's records by manipulating ids,
 * filters and the organization switch. Every attempt must fail.
 */
class TenantIsolationTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Institution $instB;

    private Department $deptB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        Institution::factory()->for($this->orgA)->create();
        $this->instB = Institution::factory()->for($this->orgB)->create();
        $this->deptB = Department::factory()->for($this->orgB)->create(['institution_id' => $this->instB->id]);

        $this->adminA = $this->userWithRole($this->orgA, ['institutions.view', 'departments.view']);
        $this->actingAs($this->adminA);
    }

    public function test_listing_shows_only_own_organization(): void
    {
        $ids = $this->spa()->getJson('/api/v1/institutions')->assertOk()->json('data.*.organization_id');
        $this->assertNotEmpty($ids);
        $this->assertSame([$this->orgA->id], array_values(array_unique($ids)));

        $this->spa()->getJson('/api/v1/departments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_other_organizations_records_are_not_found_by_id(): void
    {
        $this->spa()->getJson("/api/v1/institutions/{$this->instB->id}")->assertNotFound();
        $this->spa()->getJson("/api/v1/departments/{$this->deptB->id}")->assertNotFound();
    }

    public function test_filters_cannot_reach_other_organizations(): void
    {
        $this->spa()->getJson("/api/v1/departments?institution_id={$this->instB->id}")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->spa()->getJson("/api/v1/institutions?organization_id={$this->orgB->id}")
            ->assertOk()->assertJsonMissing(['id' => $this->instB->id]);
    }

    public function test_cannot_switch_into_an_organization_without_membership(): void
    {
        $this->spa()->postJson('/api/v1/auth/switch-organization', ['organization_id' => $this->orgB->id])->assertForbidden();
        $this->spa()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.current_organization.id', $this->orgA->id);
    }

    public function test_tampered_session_organization_is_ignored(): void
    {
        $this->withSession(['current_organization_id' => $this->orgB->id]);

        $this->spa()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.current_organization.id', $this->orgA->id);
        $this->spa()->getJson("/api/v1/institutions/{$this->instB->id}")->assertNotFound();
    }

    public function test_member_of_two_organizations_sees_each_only_after_switching(): void
    {
        $this->userWithRole($this->orgB, ['institutions.view'], user: $this->adminA);

        $this->spa()->postJson('/api/v1/auth/switch-organization', ['organization_id' => $this->orgB->id])
            ->assertOk()->assertJsonPath('data.current_organization.id', $this->orgB->id);

        $this->spa()->getJson("/api/v1/institutions/{$this->instB->id}")->assertOk();
        // In org B this user holds institutions.view only — departments are forbidden.
        $this->spa()->getJson('/api/v1/departments')->assertForbidden();
    }

    public function test_inactive_organization_is_not_accessible(): void
    {
        $this->orgA->update(['is_active' => false]);

        $this->spa()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.current_organization', null);
        $this->spa()->getJson('/api/v1/institutions')->assertForbidden();
    }

    public function test_super_admin_sees_the_organization_they_switch_to(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($super);

        $this->spa()->postJson('/api/v1/auth/switch-organization', ['organization_id' => $this->orgB->id])->assertOk();
        $this->spa()->getJson("/api/v1/departments/{$this->deptB->id}")->assertOk();
        $this->spa()->getJson('/api/v1/institutions')->assertOk()->assertJsonMissing(['organization_id' => $this->orgA->id]);
    }
}
