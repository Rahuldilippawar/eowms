<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\Department;
use App\Models\Institution;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Access\DefaultRoles;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Institution $college;

    private Institution $school;

    private Department $physics;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        Notification::fake();
        $this->org = Organization::factory()->create();
        DefaultRoles::createFor($this->org);
        $this->college = Institution::factory()->for($this->org)->create();
        $this->school = Institution::factory()->for($this->org)->create();
        $this->physics = Department::factory()->for($this->org)->create(['institution_id' => $this->college->id]);

        $this->admin = User::factory()->create();
        $this->assign($this->admin, 'org_admin');
    }

    private function assign(User $user, string $roleKey, array $institutions = [], array $departments = []): RoleAssignment
    {
        $role = Role::withoutGlobalScopes()->where(['organization_id' => $this->org->id, 'key' => $roleKey])->firstOrFail();
        $a = RoleAssignment::withoutGlobalScopes()->create(['user_id' => $user->id, 'role_id' => $role->id, 'organization_id' => $this->org->id]);
        $a->institutions()->sync($institutions);
        $a->departments()->sync($departments);

        return $a;
    }

    private function roleId(string $key): int
    {
        return Role::withoutGlobalScopes()->where(['organization_id' => $this->org->id, 'key' => $key])->value('id');
    }

    public function test_admin_creates_a_user_who_receives_a_set_password_link(): void
    {
        $this->actingAs($this->admin);

        $response = $this->spa()->postJson('/api/v1/users', [
            'name' => 'New Teacher', 'email' => 'teacher@example.com',
            'role_id' => $this->roleId('hod'), 'department_ids' => [$this->physics->id],
            'is_super_admin' => true, // must be ignored
        ])->assertCreated()->assertJsonMissingPath('data.password');

        $user = User::where('email', 'teacher@example.com')->firstOrFail();
        $this->assertFalse($user->is_super_admin);
        $this->assertSame('hod', $response->json('data.role_assignments.0.role.key'));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_existing_email_and_scoped_role_without_units_are_rejected(): void
    {
        $this->actingAs($this->admin);

        $this->spa()->postJson('/api/v1/users', ['name' => 'X', 'email' => $this->admin->email, 'role_id' => $this->roleId('staff'), 'department_ids' => [$this->physics->id]])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->spa()->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'role_id' => $this->roleId('hod')])
            ->assertStatus(422)->assertJsonValidationErrors('department_ids');
    }

    public function test_principal_cannot_hand_out_admin_or_reach_outside_their_institution(): void
    {
        $principal = User::factory()->create();
        $this->assign($principal, 'principal', institutions: [$this->college->id]);
        // Give the principal the right to create users and assign roles, limited to their college.
        $managerRole = Role::create(['key' => 'mgr', 'name' => 'Mgr', 'organization_id' => $this->org->id]);
        $managerRole->permissions()->sync(Permission::whereIn('key', ['users.create', 'roles.assign', 'users.view', 'departments.view'])
            ->pluck('id')->mapWithKeys(fn ($id) => [$id => ['scope' => PermissionScope::Institutions->value]])->all());
        $this->assign($principal, 'mgr', institutions: [$this->college->id]);
        $this->actingAs($principal);

        $this->spa()->postJson('/api/v1/users', ['name' => 'A', 'email' => 'a@example.com', 'role_id' => $this->roleId('org_admin')])
            ->assertStatus(422)->assertJsonValidationErrors('role_id');
        $this->spa()->postJson('/api/v1/users', ['name' => 'B', 'email' => 'b@example.com', 'role_id' => $this->roleId('principal'), 'institution_ids' => [$this->school->id]])
            ->assertStatus(422);
        $this->spa()->postJson('/api/v1/users', ['name' => 'C', 'email' => 'c@example.com', 'role_id' => $this->roleId('hod'), 'department_ids' => [$this->physics->id]])
            ->assertCreated();
    }

    public function test_nobody_changes_their_own_roles(): void
    {
        $this->actingAs($this->admin);
        $own = $this->admin->roleAssignments()->withoutGlobalScopes()->first();

        $this->spa()->postJson("/api/v1/users/{$this->admin->id}/role-assignments", ['role_id' => $this->roleId('staff'), 'department_ids' => [$this->physics->id]])
            ->assertStatus(422)->assertJsonValidationErrors('user');
        $this->spa()->deleteJson("/api/v1/role-assignments/{$own->id}")->assertStatus(422);
        $this->spa()->postJson("/api/v1/users/{$this->admin->id}/active", ['is_active' => false])->assertStatus(422);
    }

    public function test_the_last_organization_admin_cannot_be_removed(): void
    {
        $second = User::factory()->create();
        $secondAdmin = $this->assign($second, 'org_admin');
        $this->actingAs($this->admin);

        // Two admins: removing one is fine…
        $this->spa()->deleteJson("/api/v1/role-assignments/{$secondAdmin->id}")->assertNoContent();

        // …but a super admin removing the only remaining admin is refused.
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->spa()->postJson('/api/v1/auth/switch-organization', ['organization_id' => $this->org->id])->assertOk();
        $last = RoleAssignment::withoutGlobalScopes()->where('user_id', $this->admin->id)->first();
        $this->spa()->deleteJson("/api/v1/role-assignments/{$last->id}")->assertStatus(422);
    }

    public function test_deactivating_a_user_ends_their_sessions_and_blocks_login(): void
    {
        $staff = User::factory()->create(['password' => 'secret-pass-1']);
        $this->assign($staff, 'staff', departments: [$this->physics->id]);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $staff->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($this->admin);

        $this->spa()->postJson("/api/v1/users/{$staff->id}/active", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseMissing('sessions', ['id' => 's1']);

        $this->app['auth']->forgetGuards();
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'secret-pass-1'])->assertStatus(422);
    }

    public function test_accounts_shared_with_another_organization_are_protected(): void
    {
        $shared = User::factory()->create();
        $this->assign($shared, 'staff', departments: [$this->physics->id]);
        $this->userWithRole(Organization::factory()->create(), ['departments.view'], user: $shared);
        $this->actingAs($this->admin);

        $this->spa()->putJson("/api/v1/users/{$shared->id}", ['name' => 'Changed', 'email' => $shared->email])->assertStatus(422);
        $this->spa()->postJson("/api/v1/users/{$shared->id}/active", ['is_active' => false])->assertStatus(422);
        // Their roles here can still be removed.
        $here = RoleAssignment::withoutGlobalScopes()->where(['user_id' => $shared->id, 'organization_id' => $this->org->id])->first();
        $this->spa()->deleteJson("/api/v1/role-assignments/{$here->id}")->assertNoContent();
    }

    public function test_users_of_other_organizations_are_invisible(): void
    {
        $foreign = $this->userWithRole(Organization::factory()->create(), ['departments.view']);
        $this->actingAs($this->admin);

        $this->spa()->getJson("/api/v1/users/{$foreign->id}")->assertNotFound();
        $this->spa()->putJson("/api/v1/users/{$foreign->id}", ['name' => 'X', 'email' => $foreign->email])->assertNotFound();
        $this->spa()->postJson("/api/v1/users/{$foreign->id}/role-assignments", ['role_id' => $this->roleId('staff'), 'department_ids' => [$this->physics->id]])->assertNotFound();
        $this->spa()->getJson('/api/v1/users?per_page=100')->assertOk()->assertJsonMissing(['email' => $foreign->email]);
    }

    public function test_department_scoped_viewer_sees_only_people_of_their_departments(): void
    {
        $physicsStaff = User::factory()->create();
        $this->assign($physicsStaff, 'staff', departments: [$this->physics->id]);
        $schoolDept = Department::factory()->for($this->org)->create(['institution_id' => $this->school->id]);
        $schoolStaff = User::factory()->create();
        $this->assign($schoolStaff, 'staff', departments: [$schoolDept->id]);

        $hod = User::factory()->create();
        $this->assign($hod, 'hod', departments: [$this->physics->id]);
        $this->actingAs($hod);

        $emails = $this->spa()->getJson('/api/v1/users?per_page=100')->assertOk()->json('data.*.email');
        $this->assertContains($physicsStaff->email, $emails);
        $this->assertNotContains($schoolStaff->email, $emails);
        $this->spa()->getJson("/api/v1/users/{$schoolStaff->id}")->assertNotFound();
    }

    public function test_admin_sends_a_password_reset_link(): void
    {
        $staff = User::factory()->create();
        $this->assign($staff, 'staff', departments: [$this->physics->id]);
        $this->actingAs($this->admin);

        $this->spa()->postJson("/api/v1/users/{$staff->id}/password-reset")->assertOk();
        Notification::assertSentTo($staff, ResetPassword::class);
    }
}
