<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\Department;
use App\Models\Institution;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class PermissionScopeTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Institution $college;

    private Institution $school;

    private Department $central;

    private Department $physics;

    private Department $primary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();

        $this->org = Organization::factory()->create();
        $this->college = Institution::factory()->for($this->org)->create();
        $this->school = Institution::factory()->for($this->org)->create();
        $this->central = Department::factory()->for($this->org)->create();
        $this->physics = Department::factory()->for($this->org)->create(['institution_id' => $this->college->id]);
        $this->primary = Department::factory()->for($this->org)->create(['institution_id' => $this->school->id]);
    }

    /** @return list<int> */
    private function departmentIds(): array
    {
        $ids = $this->spa()->getJson('/api/v1/departments')->assertOk()->json('data.*.id');
        sort($ids);

        return $ids;
    }

    public function test_without_the_permission_the_api_refuses(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['institutions.view']));

        $this->spa()->getJson('/api/v1/departments')->assertForbidden();
        $this->spa()->getJson("/api/v1/departments/{$this->physics->id}")->assertForbidden();
    }

    public function test_organization_scope_sees_everything_in_the_organization(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['departments.view']));

        $this->assertSame([$this->central->id, $this->physics->id, $this->primary->id], $this->departmentIds());
    }

    public function test_institution_scope_sees_only_its_institutions(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['departments.view', 'institutions.view'], PermissionScope::Institutions, institutionIds: [$this->college->id]));

        $this->assertSame([$this->physics->id], $this->departmentIds());
        $this->spa()->getJson("/api/v1/departments/{$this->primary->id}")->assertNotFound();
        $this->spa()->getJson("/api/v1/departments/{$this->central->id}")->assertNotFound();
        $this->spa()->getJson("/api/v1/institutions/{$this->school->id}")->assertNotFound();
        $this->spa()->getJson("/api/v1/institutions/{$this->college->id}")->assertOk();
    }

    public function test_department_scope_sees_only_its_departments(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['departments.view', 'institutions.view'], PermissionScope::Departments, departmentIds: [$this->physics->id]));

        $this->assertSame([$this->physics->id], $this->departmentIds());
        $this->spa()->getJson("/api/v1/departments/{$this->central->id}")->assertNotFound();
        // The institution that holds the department is visible, the other one is not.
        $this->spa()->getJson("/api/v1/institutions/{$this->college->id}")->assertOk();
        $this->spa()->getJson("/api/v1/institutions/{$this->school->id}")->assertNotFound();
    }

    public function test_scope_with_no_assigned_ids_sees_nothing(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['departments.view'], PermissionScope::Departments));

        $this->assertSame([], $this->departmentIds());
    }

    public function test_the_broadest_of_several_roles_wins(): void
    {
        $user = $this->userWithRole($this->org, ['departments.view'], PermissionScope::Departments, departmentIds: [$this->physics->id]);
        $this->userWithRole($this->org, ['departments.view'], PermissionScope::Organization, user: $user);
        $this->actingAs($user);

        $this->assertCount(3, $this->departmentIds());
    }

    public function test_me_lists_permissions_of_the_current_organization_only(): void
    {
        $user = $this->userWithRole($this->org, ['departments.view']);
        $other = Organization::factory()->create();
        $this->userWithRole($other, ['institutions.edit'], user: $user);
        $this->actingAs($user);

        $this->spa()->postJson('/api/v1/auth/switch-organization', ['organization_id' => $this->org->id])->assertOk();
        $this->spa()->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.current_organization.id', $this->org->id)
            ->assertJsonPath('data.permissions', ['departments.view'])
            ->assertJsonCount(2, 'data.organizations');

        $this->spa()->postJson('/api/v1/auth/switch-organization', ['organization_id' => $other->id])->assertOk()
            ->assertJsonPath('data.permissions', ['institutions.edit']);
    }
}
