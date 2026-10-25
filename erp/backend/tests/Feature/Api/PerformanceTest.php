<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_figures_scope_and_export(): void
    {
        $this->setUpPermissions();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00', 'Asia/Kolkata'));
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-20 12:00', 'Asia/Kolkata'));
        $org = Organization::factory()->create();
        $dept = Department::factory()->for($org)->create();
        $otherDept = Department::factory()->for($org)->create();
        $user = $this->userWithRole($org, []);
        $e = Employee::factory()->in($dept)->create(['user_id' => $user->id]);
        Employee::factory()->in($otherDept)->create();
        $boss = User::factory()->create();

        foreach ([['2026-07-01', 'present'], ['2026-07-02', 'late'], ['2026-07-03', 'leave'], ['2026-07-04', 'absent']] as [$d, $s]) {
            AttendanceRecord::withoutGlobalScopes()->create(['organization_id' => $org->id, 'employee_id' => $e->id, 'work_date' => $d, 'status' => $s, 'source' => 'manual']);
        }
        foreach ([['2026-07-01', 3], ['2026-07-01', 1.5], ['2026-07-02', 4]] as [$d, $h]) {
            WorkEntry::withoutGlobalScopes()->create(['organization_id' => $org->id, 'employee_id' => $e->id, 'work_date' => $d, 'title' => 't', 'hours' => $h, 'status' => 'completed', 'created_by' => $user->id]);
        }
        $task = fn (string $due, string $status, ?string $completed = null) => Task::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'title' => 't', 'priority' => 'normal', 'due_date' => $due, 'assignee_employee_id' => $e->id,
            'assigned_by' => $boss->id, 'status' => $status, 'completed_at' => $completed,
        ]);
        $task('2026-07-05', 'completed', '2026-07-05 10:00:00');   // on time
        $task('2026-07-06', 'completed', '2026-07-09 10:00:00');   // late
        $task('2026-07-10', 'in_progress');                        // overdue
        $task('2026-07-25', 'open');                               // not due yet
        $task('2026-07-11', 'cancelled');                          // ignored

        $this->actingAs($user);
        $me = $this->spa()->getJson('/api/v1/me/performance?from=2026-07-01&to=2026-07-31')->assertOk()->json('data');
        $this->assertSame(['present' => 2, 'late' => 1, 'half_day' => 0, 'absent' => 1, 'leave' => 1], $me['attendance']);
        $this->assertSame(['entries' => 3, 'hours' => 8.5, 'days_logged' => 2, 'reviewed' => 0], $me['work']);
        $this->assertSame(['assigned' => 4, 'completed' => 2, 'on_time' => 1, 'overdue' => 1, 'on_time_rate' => 25], $me['tasks']);
        $this->spa()->getJson('/api/v1/performance')->assertForbidden();

        $hod = $this->userWithRole($org, ['performance.view'], PermissionScope::Departments, departmentIds: [$dept->id]);
        $this->actingAs($hod);
        $this->spa()->getJson('/api/v1/performance?from=2026-07-01&to=2026-07-31')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $e->id);
        $this->spa()->get('/api/v1/performance?format=csv', ['Accept' => 'application/json'])->assertForbidden();

        $exporter = $this->userWithRole($org, ['performance.view', 'performance.export']);
        $this->actingAs($exporter);
        $csv = $this->spa()->get('/api/v1/performance?format=csv&from=2026-07-01&to=2026-07-31')->assertOk()->streamedContent();
        $this->assertSame(3, substr_count(trim($csv), "\n") + 1, 'header + 2 employees');
    }
}
