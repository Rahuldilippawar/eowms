<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class AttendanceTest extends TestCase
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
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->for($this->org)->create();
        $this->user = $this->userWithRole($this->org, []);
        $this->employee = Employee::factory()->in($this->dept)->create(['user_id' => $this->user->id]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Local time in the organization's timezone (Asia/Kolkata by default). */
    private function at(string $local): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($local, 'Asia/Kolkata'));
        Carbon::setTestNow(CarbonImmutable::parse($local, 'Asia/Kolkata'));
    }

    public function test_on_time_check_in_is_present_and_a_second_one_is_refused(): void
    {
        $this->actingAs($this->user);
        $this->at('2026-03-02 09:40');

        $this->spa()->postJson('/api/v1/me/attendance/check-in')->assertCreated()
            ->assertJsonPath('data.status', 'present')->assertJsonPath('data.work_date', '2026-03-02');
        $this->spa()->postJson('/api/v1/me/attendance/check-in')->assertStatus(422);
        $this->assertSame(1, AttendanceRecord::count());
        $this->assertTrue(AuditLog::where(['module' => 'attendance', 'action' => 'check_in'])->exists());
    }

    public function test_check_in_after_the_grace_period_is_late(): void
    {
        $this->actingAs($this->user);
        $this->at('2026-03-02 09:46');

        $this->spa()->postJson('/api/v1/me/attendance/check-in')->assertCreated()->assertJsonPath('data.status', 'late');
    }

    public function test_short_day_becomes_half_day_on_check_out(): void
    {
        $this->actingAs($this->user);
        $this->at('2026-03-02 09:00');
        $this->spa()->postJson('/api/v1/me/attendance/check-in')->assertCreated();
        $this->at('2026-03-02 12:30');

        $this->spa()->postJson('/api/v1/me/attendance/check-out')->assertOk()->assertJsonPath('data.status', 'half_day');
        $this->spa()->postJson('/api/v1/me/attendance/check-out')->assertStatus(422);
    }

    public function test_overnight_shift_checks_out_on_the_record_of_the_start_day(): void
    {
        $this->actingAs($this->user);
        $this->at('2026-03-02 21:00');
        $this->spa()->postJson('/api/v1/me/attendance/check-in')->assertCreated();
        $this->at('2026-03-03 06:00');

        $this->spa()->postJson('/api/v1/me/attendance/check-out')->assertOk()->assertJsonPath('data.work_date', '2026-03-02');
        $this->assertNotNull(AttendanceRecord::first()->check_out_at);
    }

    public function test_user_without_an_employee_record_gets_a_clear_404(): void
    {
        $this->actingAs($this->userWithRole($this->org, []));
        $this->spa()->postJson('/api/v1/me/attendance/check-in')->assertNotFound();
    }

    public function test_manual_marking_is_scoped_and_audited(): void
    {
        $otherDept = Department::factory()->for($this->org)->create();
        $outsider = Employee::factory()->in($otherDept)->create();
        $this->actingAs($this->userWithRole($this->org, ['attendance.view', 'attendance.create', 'attendance.edit'], PermissionScope::Departments, departmentIds: [$this->dept->id]));

        $this->spa()->putJson('/api/v1/attendance', ['employee_id' => $this->employee->id, 'date' => '2026-03-02', 'status' => 'absent', 'remarks' => 'No show'])->assertOk();
        $this->spa()->putJson('/api/v1/attendance', ['employee_id' => $this->employee->id, 'date' => '2026-03-02', 'status' => 'present', 'check_in' => '09:00', 'check_out' => '17:00'])->assertOk();
        $this->assertSame(1, AttendanceRecord::where('employee_id', $this->employee->id)->count());
        $this->assertTrue(AuditLog::where(['module' => 'attendance', 'action' => 'edit'])->exists());

        $this->spa()->putJson('/api/v1/attendance', ['employee_id' => $outsider->id, 'date' => '2026-03-02', 'status' => 'absent'])->assertNotFound();
        $this->spa()->putJson('/api/v1/attendance', ['employee_id' => $this->employee->id, 'date' => now()->addDays(3)->toDateString(), 'status' => 'absent'])->assertStatus(422);
    }

    public function test_corrections_are_decided_by_an_approver_in_scope_and_applied(): void
    {
        $this->actingAs($this->user);
        $this->spa()->postJson('/api/v1/me/attendance/corrections', ['work_date' => '2026-03-02', 'requested_status' => 'present', 'requested_check_in' => '09:10', 'requested_check_out' => '17:00', 'reason' => 'Forgot to check in'])->assertCreated();
        $this->spa()->postJson('/api/v1/me/attendance/corrections', ['work_date' => '2026-03-02', 'requested_status' => 'present', 'reason' => 'Again'])->assertStatus(422);
        $correctionId = AttendanceCorrection::withoutGlobalScopes()->value('id');

        // The employee cannot approve their own correction even with the permission.
        $this->userWithRole($this->org, ['attendance.approve'], user: $this->user);
        $this->spa()->postJson("/api/v1/attendance/corrections/{$correctionId}/decide", ['approve' => true])->assertStatus(422);

        $outsiderApprover = $this->userWithRole($this->org, ['attendance.approve'], PermissionScope::Departments, departmentIds: [Department::factory()->for($this->org)->create()->id]);
        $this->actingAs($outsiderApprover);
        $this->spa()->postJson("/api/v1/attendance/corrections/{$correctionId}/decide", ['approve' => true])->assertNotFound();

        $this->actingAs($this->userWithRole($this->org, ['attendance.approve'], PermissionScope::Departments, departmentIds: [$this->dept->id]));
        $this->spa()->getJson('/api/v1/attendance/corrections')->assertOk()->assertJsonCount(1, 'data');
        $this->spa()->postJson("/api/v1/attendance/corrections/{$correctionId}/decide", ['approve' => true, 'remarks' => 'OK'])->assertOk();
        $this->spa()->postJson("/api/v1/attendance/corrections/{$correctionId}/decide", ['approve' => false])->assertStatus(422);

        $record = AttendanceRecord::where('employee_id', $this->employee->id)->first();
        $this->assertSame('present', $record->status->value);
        $this->assertSame('correction', $record->source);
    }

    public function test_summary_counts_and_csv_export_needs_permission(): void
    {
        AttendanceRecord::create(['organization_id' => $this->org->id, 'employee_id' => $this->employee->id, 'department_id' => $this->dept->id, 'work_date' => '2026-03-02', 'status' => 'present', 'source' => 'manual']);
        AttendanceRecord::create(['organization_id' => $this->org->id, 'employee_id' => $this->employee->id, 'department_id' => $this->dept->id, 'work_date' => '2026-03-03', 'status' => 'absent', 'source' => 'manual']);
        $this->actingAs($this->userWithRole($this->org, ['attendance.view']));

        $this->spa()->getJson('/api/v1/attendance/summary?from=2026-03-01&to=2026-03-31&group_by=department')->assertOk()
            ->assertJsonPath('data.0.present', 1)->assertJsonPath('data.0.absent', 1);
        $this->spa()->get('/api/v1/attendance/summary?from=2026-03-01&to=2026-03-31&group_by=employee&format=csv', ['Accept' => 'text/csv'])->assertForbidden();

        $this->actingAs($this->userWithRole($this->org, ['attendance.view', 'attendance.export']));
        $csv = $this->spa()->get('/api/v1/attendance/summary?from=2026-03-01&to=2026-03-31&group_by=employee&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString($this->employee->employee_code, $csv);
        $this->assertTrue(AuditLog::where(['module' => 'attendance', 'action' => 'export'])->exists());
    }
}
