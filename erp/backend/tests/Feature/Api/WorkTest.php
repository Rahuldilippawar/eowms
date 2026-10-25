<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class WorkTest extends TestCase
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
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 18:00', 'Asia/Kolkata'));
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-10 18:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function log(array $override = []): TestResponse
    {
        return $this->spa()->postJson('/api/v1/me/work', ['work_date' => '2026-06-10', 'title' => 'Checked lab records', 'hours' => 2, 'status' => 'completed', ...$override]);
    }

    public function test_logging_rules(): void
    {
        $this->actingAs($this->user);
        $this->log()->assertCreated()->assertJsonPath('data.can_edit', true)->assertJsonPath('data.hours', 2);
        $this->log(['work_date' => '2026-06-11'])->assertStatus(422)->assertJsonValidationErrors('work_date');
        $this->log(['work_date' => '2026-06-06'])->assertStatus(422)->assertJsonValidationErrors('work_date');
        $this->log(['work_date' => '2026-06-07'])->assertCreated();
        $this->log(['hours' => 22.5])->assertStatus(422)->assertJsonValidationErrors('hours');
        $this->log(['hours' => 0.3])->assertStatus(422);

        // Only one's own tasks can be linked.
        $foreignTask = Task::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'title' => 'x', 'priority' => 'low', 'due_date' => '2026-06-20',
            'assignee_employee_id' => Employee::factory()->in($this->dept)->create()->id, 'assigned_by' => $this->user->id]);
        $this->log(['task_id' => $foreignTask->id])->assertStatus(422)->assertJsonValidationErrors('task_id');

        $this->spa()->getJson('/api/v1/me/work')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.edit_days', 3);
    }

    public function test_no_employee_record_means_no_work_log(): void
    {
        $this->actingAs($this->userWithRole($this->org, []));
        $this->log()->assertNotFound();
    }

    public function test_review_freezes_the_entry_and_follows_scope(): void
    {
        $this->actingAs($this->user);
        $id = $this->log()->json('data.id');

        $otherDept = Department::factory()->for($this->org)->create();
        $outsider = $this->userWithRole($this->org, ['daily_work.view', 'daily_work.review'], PermissionScope::Departments, departmentIds: [$otherDept->id]);
        $this->actingAs($outsider);
        $this->spa()->getJson('/api/v1/work')->assertOk()->assertJsonCount(0, 'data');
        $this->spa()->postJson("/api/v1/work/{$id}/review")->assertNotFound();

        $hod = $this->userWithRole($this->org, ['daily_work.view', 'daily_work.review'], PermissionScope::Departments, departmentIds: [$this->dept->id]);
        $this->actingAs($hod);
        $this->spa()->getJson('/api/v1/work?reviewed=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_edit', false);
        $this->spa()->postJson("/api/v1/work/{$id}/review", ['remarks' => 'Good'])->assertOk()->assertJsonPath('data.review_remarks', 'Good');
        $this->assertSame(1, UserNotification::withoutGlobalScopes()->where(['user_id' => $this->user->id, 'type' => 'work.reviewed'])->count());

        $this->actingAs($this->user);
        $this->spa()->putJson("/api/v1/me/work/{$id}", ['work_date' => '2026-06-10', 'title' => 'Changed', 'status' => 'completed'])->assertStatus(422);
        $this->spa()->deleteJson("/api/v1/me/work/{$id}")->assertStatus(422);
        $this->spa()->postJson("/api/v1/work/{$id}/review")->assertForbidden();
    }

    public function test_reviewer_cannot_review_their_own_work(): void
    {
        $self = $this->userWithRole($this->org, ['daily_work.view', 'daily_work.review'], user: $this->user);
        $this->actingAs($self);
        $id = $this->log()->json('data.id');
        $this->spa()->postJson("/api/v1/work/{$id}/review")->assertStatus(422);
    }

    public function test_edit_and_delete_own_entry_and_not_others(): void
    {
        $this->actingAs($this->user);
        $id = $this->log()->json('data.id');
        $this->spa()->putJson("/api/v1/me/work/{$id}", ['work_date' => '2026-06-09', 'title' => 'Changed', 'hours' => 3, 'status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.title', 'Changed')->assertJsonPath('data.work_date', '2026-06-09');

        $other = $this->userWithRole($this->org, []);
        Employee::factory()->in($this->dept)->create(['user_id' => $other->id]);
        $this->actingAs($other);
        $this->spa()->deleteJson("/api/v1/me/work/{$id}")->assertNotFound();

        $this->actingAs($this->user);
        $this->spa()->deleteJson("/api/v1/me/work/{$id}")->assertOk();
        $this->assertDatabaseMissing('work_entries', ['id' => $id]);
    }

    public function test_export_needs_permission_and_neutralises_formulas(): void
    {
        $this->actingAs($this->user);
        $this->log(['title' => '=HYPERLINK("http://x")']);

        $viewer = $this->userWithRole($this->org, ['daily_work.view']);
        $this->actingAs($viewer);
        $this->spa()->get('/api/v1/work/export', ['Accept' => 'application/json'])->assertForbidden();

        $exporter = $this->userWithRole($this->org, ['daily_work.view', 'daily_work.export']);
        $this->actingAs($exporter);
        $csv = $this->spa()->get('/api/v1/work/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertDatabaseHas('audit_logs', ['module' => 'daily_work', 'action' => 'export']);
        $this->assertInstanceOf(WorkEntry::class, WorkEntry::withoutGlobalScopes()->first());
    }
}
