<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Institution;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class EmployeeToolsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $physics;

    private Department $chem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $college = Institution::factory()->for($this->org)->create(['code' => 'COL']);
        $this->physics = Department::factory()->for($this->org)->create(['code' => 'PHY', 'institution_id' => $college->id]);
        $this->chem = Department::factory()->for($this->org)->create(['code' => 'CHE', 'institution_id' => $college->id]);
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('staff.csv', "\xEF\xBB\xBF".$body);
    }

    private function upload(string $url, string $body): TestResponse
    {
        return $this->spa()->post($url, ['file' => $this->csv($body)], ['Accept' => 'application/json']);
    }

    public function test_preview_reports_every_problem_and_import_is_all_or_nothing(): void
    {
        Employee::factory()->in($this->physics)->create(['employee_code' => 'E-1']);
        $hr = $this->userWithRole($this->org, ['employees.create'], PermissionScope::Departments, departmentIds: [$this->physics->id]);
        $this->actingAs($hr);
        $bad = "employee_code,name,department_code,joining_date,reporting_officer_code\n"
            ."E-1,Dup existing,PHY,,\n"
            ."E-2,Ok,PHY,30/06/2026,E-3\n"
            ."E-3,Out of scope,CHE,,\n"
            ."E-4,Bad date,PHY,31/02/2026,\n"
            ."E-5,No dept,XYZ,,\n";
        $rows = $this->upload('/api/v1/employees/import/preview', $bad)->assertOk()->assertJsonPath('data.with_errors', 4)->json('data.rows');
        $this->assertArrayHasKey('employee_code', $rows[0]['errors']);
        $this->assertSame([], $rows[1]['errors']);
        $this->assertArrayHasKey('department_code', $rows[2]['errors']);
        $this->assertArrayHasKey('joining_date', $rows[3]['errors']);
        $this->assertArrayHasKey('department_code', $rows[4]['errors']);

        $this->upload('/api/v1/employees/import', $bad)->assertStatus(422)->assertJsonCount(4, 'rows');
        $this->assertSame(1, Employee::withoutGlobalScopes()->count(), 'nothing written');

        $good = "employee_code;name;department_code;institution_code;joining_date;reporting_officer_code;gender\n"
            ."E-2;Asha;PHY;COL;30/06/2026;E-3;female\n"
            ."E-3;Ravi;PHY;;2026-01-05;E-1;male\n";
        $this->upload('/api/v1/employees/import', $good)->assertCreated()->assertJsonPath('data.imported', 2);
        $asha = Employee::withoutGlobalScopes()->where('employee_code', 'E-2')->first();
        $this->assertSame('2026-06-30', $asha->joining_date->toDateString());
        $this->assertSame('E-3', Employee::withoutGlobalScopes()->find($asha->reporting_officer_id)->employee_code);
        $this->assertNotNull($asha->public_token);

        $this->upload('/api/v1/employees/import/preview', "name\nX\n")->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_bulk_move_needs_scope_over_people_and_target(): void
    {
        $a = Employee::factory()->in($this->physics)->create();
        $b = Employee::factory()->in($this->physics)->create();
        $b->departments()->attach($this->chem->id);

        $hod = $this->userWithRole($this->org, ['employees.edit'], PermissionScope::Departments, departmentIds: [$this->physics->id]);
        $this->actingAs($hod);
        $this->spa()->postJson('/api/v1/employees/bulk-move', ['employee_ids' => [$a->id], 'department_id' => $this->chem->id])->assertStatus(422);

        $principal = $this->userWithRole($this->org, ['employees.edit'], PermissionScope::Departments, departmentIds: [$this->physics->id, $this->chem->id]);
        $this->actingAs($principal);
        $this->spa()->postJson('/api/v1/employees/bulk-move', ['employee_ids' => [$a->id, $b->id], 'department_id' => $this->chem->id, 'reason' => 'Restructure'])
            ->assertOk()->assertJsonPath('data.moved', 2);
        $this->assertSame($this->chem->id, $b->fresh()->department_id);
        $this->assertSame([], $b->fresh()->departments()->pluck('departments.id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'bulk_move', 'reason' => 'Restructure']);
    }

    public function test_id_cards_only_for_people_in_scope(): void
    {
        $a = Employee::factory()->in($this->physics)->create(['blood_group' => 'B+']);
        $other = Employee::factory()->in($this->chem)->create();
        $hod = $this->userWithRole($this->org, ['employees.view'], PermissionScope::Departments, departmentIds: [$this->physics->id]);
        $this->actingAs($hod);
        $this->spa()->postJson('/api/v1/employees/id-cards', ['ids' => [$a->id, $other->id]])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.blood_group', 'B+')->assertJsonPath('data.0.public_token', $a->public_token);
    }

    public function test_modules_can_be_switched_off(): void
    {
        $admin = $this->userWithRole($this->org, ['organizations.edit', 'tasks.view', 'attendance.view']);
        $user = $this->userWithRole($this->org, []);
        Employee::factory()->in($this->physics)->create(['user_id' => $user->id]);

        $this->actingAs($admin);
        $this->spa()->putJson('/api/v1/organization/modules', ['disabled' => ['tasks', 'attendance']])->assertOk()->assertJsonPath('data.disabled', ['attendance', 'tasks']);
        $this->spa()->putJson('/api/v1/organization/modules', ['disabled' => ['organizations']])->assertStatus(422);
        $me = $this->spa()->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(['attendance', 'tasks'], $me->json('data.disabled_modules'));
        $this->assertNotContains('tasks.view', $me->json('data.permissions'));
        $this->spa()->getJson('/api/v1/attendance?date=2026-01-01')->assertForbidden();

        $this->actingAs($user);
        $this->spa()->getJson('/api/v1/tasks')->assertForbidden();
        $this->spa()->postJson('/api/v1/me/attendance/check-in')->assertForbidden();
        $this->spa()->getJson('/api/v1/me/leave')->assertOk();

        $this->actingAs($this->userWithRole($this->org, ['organizations.edit'], PermissionScope::Departments, departmentIds: [$this->physics->id]));
        $this->spa()->putJson('/api/v1/organization/modules', ['disabled' => []])->assertForbidden();
    }

    public function test_forced_password_change(): void
    {
        $user = $this->userWithRole($this->org, []);
        $user->forceFill(['must_change_password' => true, 'password' => 'Temp1234'])->save();
        $this->actingAs($user);
        DB::table('sessions')->insert(['id' => 'other-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->spa()->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->spa()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->spa()->postJson('/api/v1/auth/change-password', ['current_password' => 'wrong', 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])->assertStatus(422);
        $this->spa()->postJson('/api/v1/auth/change-password', ['current_password' => 'Temp1234', 'password' => 'Temp1234', 'password_confirmation' => 'Temp1234'])->assertStatus(422);
        $this->spa()->postJson('/api/v1/auth/change-password', ['current_password' => 'Temp1234', 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertOk()->assertJsonPath('data.must_change_password', false);
        $this->spa()->getJson('/api/v1/dashboard')->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $this->assertFalse(User::find($user->id)->must_change_password);
    }
}
