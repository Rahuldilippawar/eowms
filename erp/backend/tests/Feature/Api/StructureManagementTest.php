<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Institution;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class StructureManagementTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Institution $college;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->college = Institution::factory()->for($this->org)->create(['code' => 'COL']);
        $this->school = Institution::factory()->for($this->org)->create(['code' => 'SCH']);
    }

    private function admin(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['institutions.create', 'institutions.edit', 'institutions.activate', 'institutions.view',
            'departments.create', 'departments.edit', 'departments.activate', 'departments.view']));
    }

    private function principalOfCollege(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['institutions.create', 'institutions.edit', 'institutions.view', 'departments.create', 'departments.edit', 'departments.view'],
            PermissionScope::Institutions, institutionIds: [$this->college->id]));
    }

    public function test_admin_creates_edits_and_deactivates_an_institution_with_audit(): void
    {
        $this->admin();

        $id = $this->spa()->postJson('/api/v1/institutions', ['type' => 'school', 'name' => 'New School', 'code' => 'NS1'])
            ->assertCreated()->assertJsonPath('data.organization_id', $this->org->id)->json('data.id');

        $this->spa()->putJson("/api/v1/institutions/{$id}", ['type' => 'school', 'name' => 'Renamed School', 'code' => 'NS1'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed School');

        $this->spa()->postJson("/api/v1/institutions/{$id}/active", ['is_active' => false, 'reason' => 'Merged'])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $update = AuditLog::where(['module' => 'institutions', 'action' => 'update', 'auditable_id' => $id])->firstOrFail();
        $this->assertSame(['name' => 'New School'], $update->old_values);
        $this->assertSame(['name' => 'Renamed School'], $update->new_values);
        $this->assertSame('Merged', AuditLog::where(['action' => 'deactivate', 'auditable_id' => $id])->value('reason'));
    }

    public function test_institution_codes_are_unique_per_organization_only(): void
    {
        $this->admin();
        $this->spa()->postJson('/api/v1/institutions', ['type' => 'college', 'name' => 'Dup', 'code' => 'COL'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $other = Organization::factory()->create();
        Institution::factory()->for($other)->create(['code' => 'XYZ']);
        $this->spa()->postJson('/api/v1/institutions', ['type' => 'college', 'name' => 'Same code elsewhere', 'code' => 'XYZ'])->assertCreated();
    }

    public function test_principal_cannot_create_institutions_and_edits_only_their_own(): void
    {
        $this->principalOfCollege();

        $this->spa()->postJson('/api/v1/institutions', ['type' => 'college', 'name' => 'X', 'code' => 'X1'])->assertForbidden();
        $this->spa()->putJson("/api/v1/institutions/{$this->school->id}", ['type' => 'school', 'name' => 'Hijack', 'code' => 'SCH'])->assertNotFound();
        $this->spa()->putJson("/api/v1/institutions/{$this->college->id}", ['type' => 'college', 'name' => 'Our College', 'code' => 'COL'])->assertOk();
    }

    public function test_department_placement_follows_scope(): void
    {
        $this->principalOfCollege();

        $this->spa()->postJson('/api/v1/departments', ['name' => 'Central', 'code' => 'C1'])
            ->assertStatus(422)->assertJsonValidationErrors('institution_id');
        $this->spa()->postJson('/api/v1/departments', ['name' => 'School dept', 'code' => 'S1', 'institution_id' => $this->school->id])
            ->assertStatus(422)->assertJsonValidationErrors('institution_id');
        $id = $this->spa()->postJson('/api/v1/departments', ['name' => 'Chemistry', 'code' => 'CHE', 'institution_id' => $this->college->id])
            ->assertCreated()->json('data.id');

        // …and it cannot be moved out of the principal's college.
        $this->spa()->putJson("/api/v1/departments/{$id}", ['name' => 'Chemistry', 'code' => 'CHE', 'institution_id' => $this->school->id])
            ->assertStatus(422);
        $this->assertSame($this->college->id, Department::find($id)->institution_id);
    }

    public function test_admin_can_create_central_departments_and_codes_are_unique_per_institution(): void
    {
        $this->admin();

        $this->spa()->postJson('/api/v1/departments', ['name' => 'IT', 'code' => 'IT'])->assertCreated()->assertJsonPath('data.is_central', true);
        $this->spa()->postJson('/api/v1/departments', ['name' => 'IT again', 'code' => 'IT'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->spa()->postJson('/api/v1/departments', ['name' => 'College IT', 'code' => 'IT', 'institution_id' => $this->college->id])->assertCreated();
    }

    public function test_cannot_attach_another_organizations_institution(): void
    {
        $this->admin();
        $foreign = Institution::factory()->for(Organization::factory())->create();

        $this->spa()->postJson('/api/v1/departments', ['name' => 'Sneaky', 'code' => 'SN', 'institution_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('institution_id');
        $this->spa()->putJson("/api/v1/institutions/{$foreign->id}", ['type' => 'college', 'name' => 'x', 'code' => 'x'])->assertNotFound();
    }

    public function test_view_only_users_cannot_change_anything(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['institutions.view', 'departments.view']));

        $this->spa()->postJson('/api/v1/institutions', ['type' => 'college', 'name' => 'X', 'code' => 'X'])->assertForbidden();
        $this->spa()->putJson("/api/v1/institutions/{$this->college->id}", ['type' => 'college', 'name' => 'X', 'code' => 'COL'])->assertForbidden();
        $this->spa()->postJson("/api/v1/institutions/{$this->college->id}/active", ['is_active' => false])->assertForbidden();
    }
}
