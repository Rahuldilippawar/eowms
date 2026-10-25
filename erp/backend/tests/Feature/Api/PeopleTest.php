<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Institution;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class PeopleTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Institution $college;

    private Department $physics;

    private Department $chemistry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->college = Institution::factory()->for($this->org)->create();
        $this->physics = Department::factory()->for($this->org)->create(['institution_id' => $this->college->id]);
        $this->chemistry = Department::factory()->for($this->org)->create(['institution_id' => $this->college->id]);
    }

    public function test_department_head_manages_employees_only_in_their_department(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['employees.view', 'employees.create', 'employees.edit'], PermissionScope::Departments, departmentIds: [$this->physics->id]));

        $id = $this->spa()->postJson('/api/v1/employees', ['employee_code' => 'P-1', 'name' => 'Lab Assistant', 'department_id' => $this->physics->id])
            ->assertCreated()->assertJsonPath('data.institution.id', $this->college->id)->json('data.id');
        $this->spa()->postJson('/api/v1/employees', ['employee_code' => 'C-1', 'name' => 'Elsewhere', 'department_id' => $this->chemistry->id])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->spa()->putJson("/api/v1/employees/{$id}", ['employee_code' => 'P-1', 'name' => 'Moved', 'department_id' => $this->chemistry->id])
            ->assertStatus(422);

        $other = Employee::factory()->in($this->chemistry)->create();
        $this->spa()->getJson("/api/v1/employees/{$other->id}")->assertNotFound();
        $this->assertSame([$id], $this->spa()->getJson('/api/v1/employees')->json('data.*.id'));
    }

    public function test_extra_department_assignment_makes_the_employee_visible_there(): void
    {
        $shared = Employee::factory()->in($this->chemistry)->create();
        $shared->departments()->sync([$this->physics->id]);
        $this->actingAs($this->userWithRole($this->org, ['employees.view'], PermissionScope::Departments, departmentIds: [$this->physics->id]));

        $this->spa()->getJson("/api/v1/employees/{$shared->id}")->assertOk();
    }

    public function test_codes_are_unique_and_accounts_link_to_one_employee(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['employees.view', 'employees.create']));
        $account = $this->userWithRole($this->org, []);
        $outsider = User::factory()->create();

        $this->spa()->postJson('/api/v1/employees', ['employee_code' => 'X1', 'name' => 'A', 'department_id' => $this->physics->id, 'user_id' => $account->id])->assertCreated();
        $this->spa()->postJson('/api/v1/employees', ['employee_code' => 'X1', 'name' => 'B', 'department_id' => $this->physics->id])->assertStatus(422)->assertJsonValidationErrors('employee_code');
        $this->spa()->postJson('/api/v1/employees', ['employee_code' => 'X2', 'name' => 'C', 'department_id' => $this->physics->id, 'user_id' => $account->id])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->spa()->postJson('/api/v1/employees', ['employee_code' => 'X3', 'name' => 'D', 'department_id' => $this->physics->id, 'user_id' => $outsider->id])->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_cross_organization_people_are_invisible(): void
    {
        $foreignDept = Department::factory()->for(Organization::factory())->create();
        $foreign = Employee::factory()->in($foreignDept)->create();
        $foreignStudent = Student::factory()->in(Institution::factory()->for($foreignDept->organization)->create())->create();
        $this->actingAs($this->userWithRole($this->org, ['employees.view', 'employees.create', 'students.view']));

        $this->spa()->getJson("/api/v1/employees/{$foreign->id}")->assertNotFound();
        $this->spa()->getJson("/api/v1/students/{$foreignStudent->id}")->assertNotFound();
        $this->spa()->postJson('/api/v1/employees', ['employee_code' => 'Z', 'name' => 'Z', 'department_id' => $foreignDept->id])->assertStatus(422);
    }

    public function test_student_department_must_belong_to_their_institution(): void
    {
        $school = Institution::factory()->for($this->org)->create();
        $this->actingAs($this->userWithRole($this->org, ['students.view', 'students.create']));

        $this->spa()->postJson('/api/v1/students', ['student_code' => 'S1', 'name' => 'A', 'institution_id' => $school->id, 'department_id' => $this->physics->id])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->spa()->postJson('/api/v1/students', ['student_code' => 'S1', 'name' => 'A', 'institution_id' => $this->college->id, 'department_id' => $this->physics->id])
            ->assertCreated();
    }

    public function test_institution_scoped_user_creates_students_only_in_their_institution(): void
    {
        $school = Institution::factory()->for($this->org)->create();
        $this->actingAs($this->userWithRole($this->org, ['students.view', 'students.create'], PermissionScope::Institutions, institutionIds: [$this->college->id]));

        $this->spa()->postJson('/api/v1/students', ['student_code' => 'S2', 'name' => 'B', 'institution_id' => $school->id])->assertStatus(422);
        $this->spa()->postJson('/api/v1/students', ['student_code' => 'S2', 'name' => 'B', 'institution_id' => $this->college->id])->assertCreated();
    }
}
