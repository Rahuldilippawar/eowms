<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Institution;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Institution $college;

    private Institution $school;

    private Department $physics;

    private Department $primary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00', 'Asia/Kolkata'));
        $this->org = Organization::factory()->create();
        $this->college = Institution::factory()->for($this->org)->create(['name' => 'Arts College']);
        $this->school = Institution::factory()->for($this->org)->create(['name' => 'Bal School']);
        $this->physics = Department::factory()->for($this->org)->create(['institution_id' => $this->college->id, 'name' => 'Physics']);
        $this->primary = Department::factory()->for($this->org)->create(['institution_id' => $this->school->id, 'name' => 'Primary']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entry(Employee $e, string $date, string $status, User $by, string $title = 'Work'): WorkEntry
    {
        return WorkEntry::query()->forceCreate(['organization_id' => $this->org->id, 'employee_id' => $e->id, 'institution_id' => $e->institution_id,
            'department_id' => $e->department_id, 'work_date' => $date, 'title' => $title, 'hours' => 2, 'status' => $status, 'created_by' => $by->id]);
    }

    public function test_the_printable_work_report_follows_the_scope(): void
    {
        $admin = $this->userWithRole($this->org, ['daily_work.view']);
        $asha = Employee::factory()->in($this->physics)->create(['name' => 'Asha']);
        $ravi = Employee::factory()->in($this->primary)->create(['name' => 'Ravi']);
        $this->entry($asha, '2026-07-08', 'completed', $admin, 'Lab repair');
        $this->entry($asha, '2026-07-09', 'in_progress', $admin);
        $this->entry($ravi, '2026-07-09', 'completed', $admin);
        $this->entry($ravi, '2026-06-01', 'completed', $admin, 'Too old');

        $this->actingAs($admin);
        $data = $this->spa()->getJson('/api/v1/work/print?from=2026-07-01&to=2026-07-10')->assertOk()->json('data');
        $this->assertSame(3, $data['total']);
        $this->assertSame(['Lab repair', 'Work', 'Work'], array_column($data['entries'], 'title'));
        $this->assertSame([['Physics', 2, 1, 50], ['Primary', 1, 1, 100]], array_map(fn ($r) => [$r['department'], $r['total'], $r['completed'], $r['completion']], $data['summary']));

        // A head of physics prints only physics.
        $this->actingAs($this->userWithRole($this->org, ['daily_work.view'], PermissionScope::Departments, departmentIds: [$this->physics->id]));
        $data = $this->spa()->getJson('/api/v1/work/print?from=2026-07-01&to=2026-07-10&status=completed')->assertOk()->json('data');
        $this->assertSame(['Asha'], array_column(array_column($data['entries'], 'employee'), 'name'));
        $this->actingAs($this->userWithRole($this->org, ['departments.view']));
        $this->spa()->getJson('/api/v1/work/print')->assertForbidden();
    }

    public function test_campus_comparison_counts_within_scope_and_skips_switched_off_modules(): void
    {
        $admin = $this->userWithRole($this->org, ['institutions.view']);
        $asha = Employee::factory()->in($this->physics)->create();
        Employee::factory()->in($this->physics)->create();
        $ravi = Employee::factory()->in($this->primary)->create();
        AttendanceRecord::query()->forceCreate(['organization_id' => $this->org->id, 'employee_id' => $asha->id, 'institution_id' => $this->college->id,
            'department_id' => $this->physics->id, 'work_date' => '2026-07-10', 'status' => 'present', 'source' => 'manual']);
        $this->entry($asha, '2026-07-02', 'completed', $admin);
        $this->entry($ravi, '2026-07-03', 'pending', $admin);

        $this->actingAs($admin);
        $data = $this->spa()->getJson('/api/v1/reports/campuses')->assertOk()->json('data');
        $this->assertSame(['2026-07-01', '2026-07-10'], [$data['from'], $data['to']]);
        [$college, $school] = $data['campuses'];
        $this->assertSame(['Arts College', 1, 2, 1, 50, 1, 100], [$college['name'], $college['departments'], $college['employees'], $college['present_today'], $college['attendance_percent'], $college['work_entries'], $college['work_completion_percent']]);
        $this->assertSame(['Bal School', 1, 0, 0], [$school['name'], $school['employees'], $school['present_today'], $school['work_completion_percent']]);
        $this->assertStringContainsString('Arts College', $this->spa()->get('/api/v1/reports/campuses?format=csv')->assertOk()->streamedContent());

        $this->org->forceFill(['settings' => ['modules_disabled' => ['attendance', 'daily_work']]])->save();
        $this->assertNotContains('present_today', $this->spa()->getJson('/api/v1/reports/campuses')->json('data.metrics'));

        // Campus-wide sees its own campus; department-wide sees no campus figures.
        $this->actingAs($this->userWithRole($this->org, ['institutions.view'], PermissionScope::Institutions, institutionIds: [$this->school->id]));
        $this->assertSame(['Bal School'], array_column($this->spa()->getJson('/api/v1/reports/campuses')->json('data.campuses'), 'name'));
        $this->actingAs($this->userWithRole($this->org, ['institutions.view'], PermissionScope::Departments, departmentIds: [$this->physics->id]));
        $this->assertSame([], $this->spa()->getJson('/api/v1/reports/campuses')->json('data.campuses'));
    }
}
