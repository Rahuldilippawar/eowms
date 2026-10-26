<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private User $officer;

    private User $worker;

    private Employee $workerEmployee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->for($this->org)->create();
        $this->officer = $this->userWithRole($this->org, []);
        $officerEmployee = Employee::factory()->in($this->dept)->create(['user_id' => $this->officer->id]);
        $this->worker = $this->userWithRole($this->org, []);
        $this->workerEmployee = Employee::factory()->in($this->dept)->create(['user_id' => $this->worker->id, 'reporting_officer_id' => $officerEmployee->id]);
        $this->at('2026-05-04 10:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $local): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($local, 'Asia/Kolkata'));
        Carbon::setTestNow(CarbonImmutable::parse($local, 'Asia/Kolkata'));
    }

    private function assign(User $as, int $employeeId, array $override = []): TestResponse
    {
        $this->actingAs($as);

        return $this->spa()->postJson('/api/v1/tasks', ['title' => 'Fix projector', 'priority' => 'high', 'due_date' => '2026-05-06', 'assignee_employee_id' => $employeeId, ...$override]);
    }

    private function notificationsOf(User $user): array
    {
        return UserNotification::withoutGlobalScopes()->where('user_id', $user->id)->pluck('type')->all();
    }

    public function test_reporting_officer_assigns_and_the_assignee_is_notified(): void
    {
        $id = $this->assign($this->officer, $this->workerEmployee->id)->assertCreated()
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.code', fn ($c) => str_starts_with($c, 'TSK-'))
            ->assertJsonPath('data.actions', ['cancel', 'edit', 'comment'])->json('data.id');

        $this->assertSame(['task.assigned'], $this->notificationsOf($this->worker));
        $this->assertSame([], $this->notificationsOf($this->officer));
        $this->assertDatabaseHas('task_updates', ['task_id' => $id, 'type' => 'created']);
    }

    public function test_who_may_assign(): void
    {
        // Not yourself, not without a reporting line or tasks.create, not in the past.
        $this->assign($this->worker, $this->workerEmployee->id)->assertStatus(422)->assertJsonValidationErrors('assignee_employee_id');
        $other = Employee::factory()->in($this->dept)->create();
        $this->assign($this->worker, $other->id)->assertStatus(422);
        $this->assign($this->officer, $this->workerEmployee->id, ['due_date' => '2026-05-03'])->assertStatus(422)->assertJsonValidationErrors('due_date');

        // tasks.create reaches only the departments in scope.
        $otherDept = Department::factory()->for($this->org)->create();
        $outside = Employee::factory()->in($otherDept)->create();
        $manager = $this->userWithRole($this->org, ['tasks.create'], PermissionScope::Departments, departmentIds: [$this->dept->id]);
        $this->assign($manager, $other->id)->assertCreated();
        $this->assign($manager, $outside->id)->assertStatus(422);

        // Another organization's employee does not exist here.
        $foreign = Employee::factory()->in(Department::factory()->create())->create();
        $this->assign($manager, $foreign->id)->assertNotFound();
    }

    public function test_full_lifecycle_with_reopen_and_notifications(): void
    {
        $id = $this->assign($this->officer, $this->workerEmployee->id)->json('data.id');

        $this->actingAs($this->worker);
        $this->spa()->getJson("/api/v1/tasks/{$id}")->assertOk()->assertJsonPath('data.actions', ['start', 'submit', 'comment']);
        $this->spa()->postJson("/api/v1/tasks/{$id}/complete")->assertForbidden();
        $this->spa()->postJson("/api/v1/tasks/{$id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->spa()->postJson("/api/v1/tasks/{$id}/submit", ['note' => 'Done, bulb replaced', 'cost_incurred' => 250])->assertOk()
            ->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.cost_incurred', 250);
        $this->assertContains('task.submit', $this->notificationsOf($this->officer));

        $this->actingAs($this->officer);
        $this->spa()->postJson("/api/v1/tasks/{$id}/reopen")->assertStatus(422)->assertJsonValidationErrors('note');
        $this->spa()->postJson("/api/v1/tasks/{$id}/reopen", ['note' => 'Remote still missing'])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->actingAs($this->worker);
        // A later hand-in without a cost keeps the earlier one.
        $this->spa()->postJson("/api/v1/tasks/{$id}/submit")->assertOk()->assertJsonPath('data.cost_incurred', 250);

        $this->actingAs($this->officer);
        $this->spa()->postJson("/api/v1/tasks/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.actions', ['comment']);
        $this->spa()->postJson("/api/v1/tasks/{$id}/complete")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->spa()->postJson("/api/v1/tasks/{$id}/cancel", ['note' => 'x'])->assertStatus(422);

        $types = collect($this->spa()->getJson("/api/v1/tasks/{$id}")->json('data.updates'))->pluck('type')->all();
        $this->assertSame(['created', 'started', 'submitted', 'reopened', 'submitted', 'completed'], $types);
        $this->assertSame(['task.assigned', 'task.reopen', 'task.complete'], $this->notificationsOf($this->worker));
    }

    public function test_visibility_and_lists(): void
    {
        $id = $this->assign($this->officer, $this->workerEmployee->id)->json('data.id');
        $otherDept = Department::factory()->for($this->org)->create();

        $stranger = $this->userWithRole($this->org, []);
        $this->actingAs($stranger);
        $this->spa()->getJson("/api/v1/tasks/{$id}")->assertNotFound();
        $this->spa()->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(0, 'data');
        $this->spa()->postJson("/api/v1/tasks/{$id}/comments", ['body' => 'hi'])->assertNotFound();

        $viewer = $this->userWithRole($this->org, ['tasks.view'], PermissionScope::Departments, departmentIds: [$this->dept->id]);
        $this->actingAs($viewer);
        $this->spa()->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.actions', []);
        $outsider = $this->userWithRole($this->org, ['tasks.view'], PermissionScope::Departments, departmentIds: [$otherDept->id]);
        $this->actingAs($outsider);
        $this->spa()->getJson("/api/v1/tasks/{$id}")->assertNotFound();

        $this->actingAs($this->worker);
        $this->spa()->getJson('/api/v1/tasks?view=mine')->assertJsonCount(1, 'data');
        $this->spa()->getJson('/api/v1/tasks?view=assigned')->assertJsonCount(0, 'data');

        // Overdue once the due date has passed.
        $this->at('2026-05-07 09:00');
        $this->spa()->getJson('/api/v1/tasks?overdue=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_overdue', true);

        // Another organization's id is simply not found.
        $foreignTask = Task::withoutGlobalScopes()->create([
            'organization_id' => Organization::factory()->create()->id, 'title' => 'x', 'priority' => 'low', 'due_date' => '2026-05-10',
            'assignee_employee_id' => Employee::factory()->in(Department::factory()->create())->create()->id, 'assigned_by' => $stranger->id,
        ]);
        $this->spa()->getJson("/api/v1/tasks/{$foreignTask->id}")->assertNotFound();
    }

    public function test_reassigning_notifies_both_and_the_assignee_cannot_edit(): void
    {
        $id = $this->assign($this->officer, $this->workerEmployee->id)->json('data.id');
        $newUser = $this->userWithRole($this->org, []);
        $newEmployee = Employee::factory()->in($this->dept)->create(['user_id' => $newUser->id, 'reporting_officer_id' => $this->workerEmployee->reporting_officer_id]);

        $this->actingAs($this->worker);
        $this->spa()->putJson("/api/v1/tasks/{$id}", ['title' => 'Mine now'])->assertForbidden();

        $this->actingAs($this->officer);
        $this->spa()->putJson("/api/v1/tasks/{$id}", ['assignee_employee_id' => $newEmployee->id, 'priority' => 'urgent'])->assertOk()
            ->assertJsonPath('data.assignee.id', $newEmployee->id)->assertJsonPath('data.priority', 'urgent');
        $this->assertSame(['task.assigned'], $this->notificationsOf($newUser));
        $this->assertContains('task.unassigned', $this->notificationsOf($this->worker));

        $this->actingAs($this->worker);
        $this->spa()->getJson("/api/v1/tasks/{$id}")->assertNotFound();
    }

    public function test_reminders_are_sent_once(): void
    {
        $this->assign($this->officer, $this->workerEmployee->id, ['due_date' => '2026-05-05', 'title' => 'Due tomorrow']);
        $this->assign($this->officer, $this->workerEmployee->id, ['due_date' => '2026-05-04', 'title' => 'Due today']);

        $this->artisan('erp:task-reminders')->assertSuccessful();
        $this->artisan('erp:task-reminders')->assertSuccessful();
        $this->assertSame(1, UserNotification::withoutGlobalScopes()->where(['user_id' => $this->worker->id, 'type' => 'task.due_soon'])->count());

        $this->at('2026-05-06 08:00');
        $this->artisan('erp:task-reminders')->assertSuccessful();
        $this->artisan('erp:task-reminders')->assertSuccessful();
        $this->assertSame(2, UserNotification::withoutGlobalScopes()->where(['user_id' => $this->worker->id, 'type' => 'task.overdue'])->count());
        $this->assertSame(2, UserNotification::withoutGlobalScopes()->where(['user_id' => $this->officer->id, 'type' => 'task.overdue'])->count());
    }
}
