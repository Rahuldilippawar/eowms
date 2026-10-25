<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private LeaveType $casual;

    private User $applicant;

    private Employee $employee;

    private User $officerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        Storage::fake('local');
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->for($this->org)->create();
        $this->casual = LeaveType::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'CL', 'name' => 'Casual', 'days_per_year' => 12, 'is_paid' => true, 'is_active' => true]);

        $this->officerUser = $this->userWithRole($this->org, []);
        $officer = Employee::factory()->in($this->dept)->create(['user_id' => $this->officerUser->id]);
        $this->applicant = $this->userWithRole($this->org, []);
        $this->employee = Employee::factory()->in($this->dept)->create(['user_id' => $this->applicant->id, 'reporting_officer_id' => $officer->id]);
    }

    private function apply(array $override = []): TestResponse
    {
        return $this->spa()->post('/api/v1/me/leave', [
            'leave_type_id' => $this->casual->id, 'start_date' => '2026-04-06', 'end_date' => '2026-04-08', 'reason' => 'Family function', ...$override,
        ], ['Accept' => 'application/json']);
    }

    public function test_apply_counts_days_and_rejects_overlaps(): void
    {
        $this->actingAs($this->applicant);

        $this->apply()->assertCreated()->assertJsonPath('data.days', 3)->assertJsonPath('data.status', 'requested');
        $this->apply(['start_date' => '2026-04-08', 'end_date' => '2026-04-09'])->assertStatus(422)->assertJsonValidationErrors('start_date');
        $this->apply(['start_date' => '2026-04-10', 'end_date' => '2026-04-09'])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->apply(['start_date' => '2026-04-10', 'end_date' => '2026-04-10'])->assertCreated();
    }

    public function test_reporting_officer_approves_and_days_become_leave_except_days_worked(): void
    {
        AttendanceRecord::create(['organization_id' => $this->org->id, 'employee_id' => $this->employee->id, 'work_date' => '2026-04-07', 'check_in_at' => '2026-04-07 04:00:00', 'status' => 'present', 'source' => 'self']);
        $this->actingAs($this->applicant);
        $id = $this->apply()->json('data.id');

        // The applicant cannot decide their own request.
        $this->spa()->postJson("/api/v1/leave-requests/{$id}/decide", ['approve' => true])->assertNotFound();

        $this->actingAs($this->officerUser);
        $this->spa()->getJson('/api/v1/leave-approvals')->assertOk()->assertJsonCount(1, 'data');
        $this->spa()->postJson("/api/v1/leave-requests/{$id}/decide", ['approve' => true, 'remarks' => 'Enjoy'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->spa()->postJson("/api/v1/leave-requests/{$id}/decide", ['approve' => false])->assertStatus(422);

        $statuses = AttendanceRecord::where('employee_id', $this->employee->id)->orderBy('work_date')->pluck('status', 'work_date')->map->value->all();
        $this->assertSame(['2026-04-06' => 'leave', '2026-04-07' => 'present', '2026-04-08' => 'leave'], $statuses);
    }

    public function test_approvers_outside_scope_cannot_decide(): void
    {
        $this->actingAs($this->applicant);
        $id = $this->apply()->json('data.id');

        $this->actingAs($this->userWithRole($this->org, ['leave.approve'], PermissionScope::Departments, departmentIds: [Department::factory()->for($this->org)->create()->id]));
        $this->spa()->postJson("/api/v1/leave-requests/{$id}/decide", ['approve' => true])->assertNotFound();

        $this->actingAs($this->userWithRole($this->org, ['leave.approve'], PermissionScope::Departments, departmentIds: [$this->dept->id]));
        $this->spa()->postJson("/api/v1/leave-requests/{$id}/decide", ['approve' => false, 'remarks' => 'Busy week'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_applicant_cancels_only_while_requested(): void
    {
        $this->actingAs($this->applicant);
        $id = $this->apply()->json('data.id');
        $this->spa()->postJson("/api/v1/me/leave/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->spa()->postJson("/api/v1/me/leave/{$id}/cancel")->assertStatus(422);

        // Cancelled requests do not block the same dates.
        $this->apply()->assertCreated();
    }

    public function test_attachment_is_private_and_only_for_people_who_may_see_the_request(): void
    {
        $this->actingAs($this->applicant);
        $id = $this->apply(['attachment' => UploadedFile::fake()->create('note.pdf', 100, 'application/pdf')])->assertCreated()
            ->assertJsonPath('data.has_attachment', true)->assertJsonMissingPath('data.attachment_path')->json('data.id');

        $path = LeaveRequest::withoutGlobalScopes()->find($id)->attachment_path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith("leave/{$this->org->id}/", $path);

        $this->spa()->get("/api/v1/leave-requests/{$id}/attachment")->assertOk();
        $this->actingAs($this->officerUser);
        $this->spa()->get("/api/v1/leave-requests/{$id}/attachment")->assertOk();

        $this->actingAs($this->userWithRole($this->org, []));
        $this->spa()->getJson("/api/v1/leave-requests/{$id}/attachment")->assertNotFound();

        $this->actingAs($this->applicant);
        $this->apply(['start_date' => '2026-05-01', 'end_date' => '2026-05-01', 'attachment' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')])
            ->assertStatus(422)->assertJsonValidationErrors('attachment');
    }

    public function test_leave_of_another_organization_is_invisible(): void
    {
        $this->actingAs($this->applicant);
        $id = $this->apply()->json('data.id');

        $foreignOrg = Organization::factory()->create();
        $this->actingAs($this->userWithRole($foreignOrg, ['leave.view', 'leave.approve']));
        $this->spa()->postJson("/api/v1/leave-requests/{$id}/decide", ['approve' => true])->assertNotFound();
        $this->spa()->getJson("/api/v1/leave-requests/{$id}/attachment")->assertNotFound();
        $this->spa()->getJson('/api/v1/leave-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_leave_types_are_managed_only_with_organization_scope(): void
    {
        $this->actingAs($this->userWithRole($this->org, ['leave.manage_types'], PermissionScope::Departments, departmentIds: [$this->dept->id]));
        $this->spa()->postJson('/api/v1/leave-types', ['code' => 'ML', 'name' => 'Maternity', 'days_per_year' => 180, 'is_paid' => true, 'is_active' => true])->assertForbidden();

        $this->actingAs($this->userWithRole($this->org, ['leave.manage_types']));
        $this->spa()->postJson('/api/v1/leave-types', ['code' => 'ML', 'name' => 'Maternity', 'days_per_year' => 180, 'is_paid' => true, 'is_active' => true])->assertCreated();
        $this->spa()->postJson('/api/v1/leave-types', ['code' => 'CL', 'name' => 'Dup', 'days_per_year' => 1, 'is_paid' => true, 'is_active' => true])->assertStatus(422);
    }
}
