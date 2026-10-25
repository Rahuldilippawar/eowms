<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AuditLog;
use App\Models\CustomField;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Institution;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class EmployeeProfileTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        Storage::fake('local');
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->for($this->org)->create();
        $this->user = $this->userWithRole($this->org, []);
        $this->employee = Employee::factory()->in($this->dept)->create(['user_id' => $this->user->id]);
    }

    private function hr(array $permissions = ['employees.view', 'employees.edit'], ?Department $dept = null): User
    {
        return $this->userWithRole($this->org, $permissions, PermissionScope::Departments, departmentIds: [($dept ?? $this->dept)->id]);
    }

    public function test_self_sees_own_profile_and_updates_only_contact_details(): void
    {
        $this->actingAs($this->user);
        $this->spa()->getJson('/api/v1/me/profile')->assertOk()->assertJsonPath('data.can.self', true)->assertJsonPath('data.can.edit', false)
            ->assertJsonPath('data.private.aadhaar_masked', null);
        $this->spa()->putJson('/api/v1/me/profile', ['whatsapp' => '9811111111', 'blood_group' => 'O+', 'designation' => 'Director', 'father_name' => 'X'])
            ->assertOk()->assertJsonPath('data.whatsapp', '9811111111')->assertJsonPath('data.blood_group', 'O+')->assertJsonPath('data.father_name', null);
        $this->assertNotSame('Director', $this->employee->fresh()->designation);
        $this->spa()->putJson("/api/v1/employees/{$this->employee->id}/profile", ['father_name' => 'X'])->assertForbidden();
    }

    public function test_hr_updates_profile_within_scope_only(): void
    {
        $this->actingAs($this->hr());
        $this->spa()->putJson("/api/v1/employees/{$this->employee->id}/profile", [
            'father_name' => 'Ramesh', 'gender' => 'male', 'date_of_birth' => '1990-04-15', 'employment_type' => 'permanent', 'is_disabled' => true,
        ])->assertOk()->assertJsonPath('data.father_name', 'Ramesh')->assertJsonPath('data.date_of_birth', '1990-04-15')->assertJsonPath('data.is_disabled', true);
        $this->spa()->putJson("/api/v1/employees/{$this->employee->id}/profile", ['gender' => 'x'])->assertStatus(422);
        // Without employees.sensitive the private block is not even present.
        $this->spa()->getJson("/api/v1/employees/{$this->employee->id}/profile")->assertOk()->assertJsonMissingPath('data.private');

        $this->actingAs($this->hr(dept: Department::factory()->for($this->org)->create()));
        $this->spa()->getJson("/api/v1/employees/{$this->employee->id}/profile")->assertNotFound();

        $this->actingAs($this->hr(['employees.view']));
        $this->spa()->putJson("/api/v1/employees/{$this->employee->id}/profile", ['father_name' => 'Y'])->assertForbidden();
    }

    public function test_private_data_is_encrypted_masked_and_reveal_is_audited(): void
    {
        $this->actingAs($this->hr(['employees.view', 'employees.edit']));
        $this->spa()->putJson("/api/v1/employees/{$this->employee->id}/private", ['aadhaar_number' => '1234 5678 9012'])->assertForbidden();

        $this->actingAs($this->hr(['employees.view', 'employees.sensitive']));
        $this->spa()->putJson("/api/v1/employees/{$this->employee->id}/private", [
            'aadhaar_number' => '1234 5678 9012', 'bank_account_no' => '00112233445566', 'ifsc_code' => 'sbin0001234', 'monthly_salary' => 32000, 'pf_applicable' => true, 'uan_no' => '100200300400',
        ])->assertOk()->assertJsonPath('data.private.aadhaar_masked', '••••••••9012')->assertJsonPath('data.private.ifsc_code', 'SBIN0001234')
            ->assertJsonPath('data.private.monthly_salary', 32000)->assertJsonMissingPath('data.private.aadhaar_number');
        $this->spa()->putJson("/api/v1/employees/{$this->employee->id}/private", ['aadhaar_number' => '12345'])->assertStatus(422);

        $raw = DB::table('employee_private')->where('employee_id', $this->employee->id)->first();
        $this->assertStringNotContainsString('9012', $raw->aadhaar_number);
        $this->assertStringNotContainsString('2233', $raw->bank_account_no);
        $log = AuditLog::query()->where('action', 'private_update')->latest('id')->first();
        $this->assertStringNotContainsString('9012', json_encode($log->new_values));

        $this->spa()->postJson("/api/v1/employees/{$this->employee->id}/private/reveal")->assertOk()->assertJsonPath('data.aadhaar_number', '123456789012');
        $this->assertDatabaseHas('audit_logs', ['action' => 'private_reveal', 'auditable_id' => $this->employee->id]);

        // The employee sees their own (masked) data too.
        $this->actingAs($this->user);
        $this->spa()->getJson('/api/v1/me/profile')->assertJsonPath('data.private.bank_account_masked', '••••••••••5566');
        // Ordinary employee resources never carry private data.
        $this->actingAs($this->hr(['employees.view', 'employees.sensitive']));
        $this->spa()->getJson("/api/v1/employees/{$this->employee->id}")->assertJsonMissingPath('data.private');
    }

    public function test_photo_and_public_id_card_check(): void
    {
        $this->actingAs($this->user);
        $this->spa()->post("/api/v1/employees/{$this->employee->id}/photo", ['photo' => UploadedFile::fake()->image('me.jpg', 300, 400)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_photo', true);
        $this->spa()->get("/api/v1/employees/{$this->employee->id}/photo")->assertOk();
        $this->spa()->post("/api/v1/employees/{$this->employee->id}/photo", ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);

        $token = $this->employee->fresh()->public_token;
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/employees/{$token}")->assertOk()->assertJsonPath('data.name', $this->employee->name)
            ->assertJsonPath('data.is_active', true)->assertJsonMissingPath('data.phone')->assertJsonMissingPath('data.id');
        $this->get("/api/v1/public/employees/{$token}/photo")->assertOk();
        $this->getJson('/api/v1/public/employees/not-a-token')->assertNotFound();
        $this->getJson('/api/v1/public/employees/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();

        $this->employee->forceFill(['left_date' => '2026-01-01'])->save();
        $this->getJson("/api/v1/public/employees/{$token}")->assertJsonPath('data.is_active', false);
    }

    public function test_documents_family_and_achievements(): void
    {
        $this->actingAs($this->user);
        $docId = $this->spa()->post("/api/v1/employees/{$this->employee->id}/documents", ['document_type' => 'education', 'file' => UploadedFile::fake()->create('degree.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->json('data.documents.0.id');
        $this->spa()->post("/api/v1/employees/{$this->employee->id}/documents", ['document_type' => 'other', 'file' => UploadedFile::fake()->create('x.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->spa()->postJson("/api/v1/employees/{$this->employee->id}/family", ['name' => 'Sita', 'relation' => 'spouse', 'lives_on_campus' => true])->assertOk()->assertJsonPath('data.family_members.0.lives_on_campus', true);
        $this->spa()->postJson("/api/v1/employees/{$this->employee->id}/achievements", ['title' => 'Best teacher'])->assertForbidden();

        $stranger = $this->userWithRole($this->org, []);
        Employee::factory()->in($this->dept)->create(['user_id' => $stranger->id]);
        $this->actingAs($stranger);
        $this->spa()->get("/api/v1/employee-documents/{$docId}")->assertNotFound();
        $this->spa()->postJson("/api/v1/employees/{$this->employee->id}/family", ['name' => 'X', 'relation' => 'y'])->assertNotFound();

        $this->actingAs($this->hr());
        $this->spa()->get("/api/v1/employee-documents/{$docId}")->assertOk();
        $this->spa()->postJson("/api/v1/employees/{$this->employee->id}/achievements", ['title' => 'Best teacher', 'achieved_on' => '2025-12-01'])->assertOk()->assertJsonPath('data.achievements.0.title', 'Best teacher');
        $this->spa()->deleteJson("/api/v1/employee-documents/{$docId}")->assertOk()->assertJsonCount(0, 'data.documents');
        $this->assertSame(0, EmployeeDocument::withoutGlobalScopes()->count());
    }

    public function test_custom_fields_are_validated_and_unknown_keys_dropped(): void
    {
        $admin = $this->userWithRole($this->org, ['organizations.edit', 'employees.view', 'employees.edit']);
        $this->actingAs($admin);
        $this->spa()->postJson('/api/v1/custom-fields', ['entity' => 'employee', 'key' => 'house', 'label' => 'House', 'type' => 'select', 'options' => ['A', 'B'], 'is_required' => true])->assertCreated();
        $this->spa()->postJson('/api/v1/custom-fields', ['entity' => 'employee', 'key' => 'house', 'label' => 'Again', 'type' => 'text'])->assertStatus(422);
        $this->spa()->getJson('/api/v1/custom-fields/active?entity=employee')->assertJsonCount(1, 'data');

        $url = "/api/v1/employees/{$this->employee->id}/profile";
        $this->spa()->putJson($url, ['custom' => ['house' => 'C']])->assertStatus(422)->assertJsonValidationErrors('custom.house');
        $this->spa()->putJson($url, [])->assertStatus(422);
        $this->spa()->putJson($url, ['custom' => ['house' => 'B', 'hacker' => 'x']])->assertOk()->assertJsonPath('data.custom', ['house' => 'B']);

        $this->actingAs($this->hr(['organizations.edit']));
        $this->spa()->postJson('/api/v1/custom-fields', ['entity' => 'employee', 'key' => 'x', 'label' => 'X', 'type' => 'text'])->assertForbidden();
        $this->assertSame(1, CustomField::withoutGlobalScopes()->count());
    }

    public function test_student_and_work_forms_use_their_own_fields(): void
    {
        $admin = $this->userWithRole($this->org, ['organizations.edit', 'students.create']);
        $this->actingAs($admin);
        $this->spa()->postJson('/api/v1/custom-fields', ['entity' => 'student', 'key' => 'bus_route', 'label' => 'Bus route', 'type' => 'number'])->assertCreated();
        $this->spa()->postJson('/api/v1/custom-fields', ['entity' => 'work_entry', 'key' => 'site', 'label' => 'Site', 'type' => 'text', 'is_required' => true])->assertCreated();
        $institution = Institution::factory()->for($this->org)->create();
        $this->spa()->postJson('/api/v1/students', ['student_code' => 'S-1', 'name' => 'Asha', 'institution_id' => $institution->id, 'custom' => ['bus_route' => '7']])
            ->assertCreated()->assertJsonPath('data.custom.bus_route', 7);

        $this->actingAs($this->user);
        $this->spa()->postJson('/api/v1/me/work', ['work_date' => now('Asia/Kolkata')->toDateString(), 'title' => 'x', 'status' => 'completed'])->assertStatus(422)->assertJsonValidationErrors('custom.site');
        $this->spa()->postJson('/api/v1/me/work', ['work_date' => now('Asia/Kolkata')->toDateString(), 'title' => 'x', 'status' => 'completed', 'custom' => ['site' => 'Lab 2']])
            ->assertCreated()->assertJsonPath('data.custom.site', 'Lab 2');
    }
}
