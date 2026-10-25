<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Access\DefaultRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
    }

    private function roleManager(PermissionScope $scope = PermissionScope::Organization, array $institutionIds = []): void
    {
        $this->actingAs($this->userWithRole($this->org,
            ['roles.view', 'roles.create', 'roles.edit', 'roles.delete', 'departments.view', 'departments.edit'],
            $scope, institutionIds: $institutionIds));
    }

    public function test_creates_a_custom_role_with_permissions_the_actor_holds(): void
    {
        $this->roleManager();

        $id = $this->spa()->postJson('/api/v1/roles', [
            'name' => 'Clerk',
            'permissions' => [['key' => 'departments.view', 'scope' => 'departments']],
        ])->assertCreated()->assertJsonPath('data.permissions', [['key' => 'departments.view', 'scope' => 'departments']])->json('data.id');

        $this->assertSame('clerk', Role::find($id)->key);
        $this->assertTrue(AuditLog::where(['module' => 'roles', 'action' => 'create', 'auditable_id' => $id])->exists());
    }

    public function test_cannot_grant_a_permission_the_actor_does_not_hold(): void
    {
        $this->roleManager();

        $this->spa()->postJson('/api/v1/roles', ['name' => 'Escalate', 'permissions' => [['key' => 'users.create', 'scope' => 'organization']]])
            ->assertStatus(422)->assertJsonValidationErrors('permissions');
        $this->assertFalse(Role::where('name', 'Escalate')->exists());
    }

    public function test_cannot_grant_a_wider_scope_than_the_actor_has(): void
    {
        $college = Institution::factory()->for($this->org)->create();
        $this->roleManager(PermissionScope::Institutions, [$college->id]);

        $this->spa()->postJson('/api/v1/roles', ['name' => 'Wide', 'permissions' => [['key' => 'departments.view', 'scope' => 'organization']]])
            ->assertStatus(422);
        $this->spa()->postJson('/api/v1/roles', ['name' => 'Narrow', 'permissions' => [['key' => 'departments.view', 'scope' => 'institutions']]])
            ->assertCreated();
    }

    public function test_cannot_edit_a_role_the_actor_holds(): void
    {
        $user = $this->userWithRole($this->org, ['roles.view', 'roles.edit', 'departments.view']);
        $this->actingAs($user);
        $ownRole = $user->roleAssignments()->withoutGlobalScopes()->first()->role_id;

        $this->spa()->putJson("/api/v1/roles/{$ownRole}", ['name' => 'Mine', 'permissions' => [['key' => 'departments.view', 'scope' => 'organization']]])
            ->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_built_in_and_in_use_roles_cannot_be_deleted(): void
    {
        DefaultRoles::createFor($this->org);
        $this->roleManager();
        $system = Role::where('key', 'staff')->first();

        $this->spa()->deleteJson("/api/v1/roles/{$system->id}")->assertStatus(422);

        $custom = Role::create(['key' => 'temp', 'name' => 'Temp', 'organization_id' => $this->org->id]);
        $this->userWithRole($this->org, []); // unrelated
        RoleAssignment::withoutGlobalScopes()->create(['user_id' => User::factory()->create()->id, 'role_id' => $custom->id, 'organization_id' => $this->org->id]);
        $this->spa()->deleteJson("/api/v1/roles/{$custom->id}")->assertStatus(422);

        $unused = Role::create(['key' => 'unused', 'name' => 'Unused', 'organization_id' => $this->org->id]);
        $this->spa()->deleteJson("/api/v1/roles/{$unused->id}")->assertNoContent();
    }

    public function test_organization_admin_role_permissions_are_locked(): void
    {
        DefaultRoles::createFor($this->org);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->spa()->postJson('/api/v1/auth/switch-organization', ['organization_id' => $this->org->id])->assertOk();
        $admin = Role::where('key', 'org_admin')->first();

        $this->spa()->putJson("/api/v1/roles/{$admin->id}", ['name' => 'Admin', 'permissions' => []])->assertStatus(422);
    }

    public function test_roles_of_other_organizations_are_not_found(): void
    {
        $this->roleManager();
        $foreign = Role::withoutGlobalScopes()->create(['key' => 'x', 'name' => 'X', 'organization_id' => Organization::factory()->create()->id]);

        $this->spa()->getJson("/api/v1/roles/{$foreign->id}")->assertNotFound();
        $this->spa()->deleteJson("/api/v1/roles/{$foreign->id}")->assertNotFound();
        $this->spa()->getJson('/api/v1/roles')->assertOk()->assertJsonMissing(['id' => $foreign->id]);
    }
}
