<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class WorkRequestTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $works;

    private Department $physics;

    private User $head;

    private User $staff;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create(['code' => 'ORG1']);
        $this->works = Department::factory()->for($this->org)->create(['code' => 'MNT']);
        $this->physics = Department::factory()->for($this->org)->create(['code' => 'PHY']);
        $this->head = $this->userWithRole($this->org, ['requests.view', 'requests.manage', 'requests.export'], PermissionScope::Departments, departmentIds: [$this->works->id]);
        $this->works->forceFill(['head_user_id' => $this->head->id])->save();
        $this->staff = $this->userWithRole($this->org, []);
        Employee::factory()->in($this->works)->create(['user_id' => $this->staff->id, 'name' => 'Plumber']);
        $this->teacher = $this->userWithRole($this->org, []);
        Employee::factory()->in($this->physics)->create(['user_id' => $this->teacher->id, 'phone' => '9876543210']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function raise(array $override = []): int
    {
        $this->actingAs($this->teacher);

        return $this->spa()->postJson('/api/v1/requests', [
            'category' => 'maintenance', 'department_id' => $this->works->id, 'title' => 'Leaking tap', 'work_description' => 'Lab sink tap leaks',
            'location' => 'Physics lab', 'priority' => 'high', ...$override,
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    }

    /** @return list<string> */
    private function types(User $u): array
    {
        return UserNotification::withoutGlobalScopes()->where('user_id', $u->id)->orderBy('id')->pluck('type')->all();
    }

    public function test_lifecycle_assign_complete_close_and_reopen(): void
    {
        $id = $this->raise();
        $r = WorkRequest::query()->findOrFail($id);
        $this->assertMatchesRegularExpression('/^REQ-\d{2}-0001$/', $r->request_code);
        $this->assertSame(['9876543210', $this->physics->id], [$r->requester_contact, $r->requesting_department_id]);
        $this->assertEqualsWithDelta(24, $r->created_at->diffInHours($r->due_at), 0.1);
        $this->assertSame(['request.new'], $this->types($this->head));

        // The requester cannot work it; the head assigns, the assignee works and completes it.
        $this->spa()->postJson("/api/v1/requests/{$id}/status", ['status' => 'in_progress'])->assertForbidden();
        $this->actingAs($this->head);
        $this->spa()->getJson('/api/v1/requests')->assertJsonCount(1, 'data')->assertJsonPath('meta.counts.pending', 1);
        $this->spa()->getJson("/api/v1/requests/{$id}/assignees")->assertJsonPath('data.0.name', 'Plumber');
        $this->spa()->postJson("/api/v1/requests/{$id}/assign", ['user_id' => $this->staff->id])->assertOk()->assertJsonPath('data.assignee.id', $this->staff->id);
        $this->actingAs($this->staff);
        $this->spa()->getJson("/api/v1/requests/{$id}")->assertOk()->assertJsonPath('data.can_handle', true)->assertJsonPath('data.can_assign', false);
        $this->spa()->postJson("/api/v1/requests/{$id}/status", ['status' => 'completed'])->assertStatus(422)->assertJsonValidationErrors('remark');
        $this->spa()->postJson("/api/v1/requests/{$id}/status", ['status' => 'completed', 'remark' => 'Washer replaced', 'cost_incurred' => 150])
            ->assertOk()->assertJsonPath('data.cost_incurred', 150)->assertJsonPath('data.completed_by', $this->staff->name);
        $this->assertSame(['request.completed'], $this->types($this->teacher));

        // The requester closes with a rating, then reopens within 7 days.
        $this->actingAs($this->teacher);
        $this->spa()->postJson("/api/v1/requests/{$id}/cancel", ['reason' => 'x'])->assertStatus(422);
        $this->spa()->postJson("/api/v1/requests/{$id}/close", ['rating' => 6])->assertStatus(422);
        $this->spa()->postJson("/api/v1/requests/{$id}/close", ['rating' => 4, 'feedback' => 'Quick'])->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.rating', 4);
        $this->spa()->postJson("/api/v1/requests/{$id}/reopen", ['reason' => 'Leaking again'])->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.reopened_count', 1)->assertJsonPath('data.rating', null);
        $this->assertContains('request.reopened', $this->types($this->staff));
        $this->spa()->getJson("/api/v1/requests/{$id}")->assertJsonPath('data.seen_by.0.name', $this->staff->name)
            ->assertJsonPath('data.actions.0.type', 'created')->assertJsonCount(5, 'data.actions');

        Carbon::setTestNow(now()->addDays(8));
        $this->actingAs($this->staff);
        $this->spa()->postJson("/api/v1/requests/{$id}/status", ['status' => 'completed', 'remark' => 'Tap replaced'])->assertOk();
        Carbon::setTestNow(now()->addDays(8));
        $this->actingAs($this->teacher);
        $this->spa()->postJson("/api/v1/requests/{$id}/reopen", ['reason' => 'Again'])->assertStatus(422);
    }

    public function test_visibility_comments_cancel_report_and_export(): void
    {
        $id = $this->raise();
        $other = $this->userWithRole($this->org, ['requests.view', 'requests.manage'], PermissionScope::Departments, departmentIds: [$this->physics->id]);
        $this->actingAs($other);
        $this->spa()->getJson("/api/v1/requests/{$id}")->assertNotFound();
        $this->spa()->getJson('/api/v1/requests')->assertJsonCount(0, 'data');

        $this->actingAs($this->head);
        $this->spa()->postJson("/api/v1/requests/{$id}/comments", ['comment' => 'Which sink?'])->assertOk();
        $this->assertContains('request.comment', $this->types($this->teacher));
        $this->actingAs($this->teacher);
        $this->spa()->postJson("/api/v1/requests/{$id}/comments", ['comment' => 'The left one'])->assertOk()->assertJsonCount(2, 'data.comments');
        $this->spa()->getJson('/api/v1/requests?mine=1')->assertJsonCount(1, 'data');
        $this->spa()->getJson('/api/v1/requests/departments')->assertJsonCount(2, 'data');
        $this->spa()->postJson("/api/v1/requests/{$id}/cancel", ['reason' => 'Fixed it myself'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->spa()->postJson("/api/v1/requests/{$id}/comments", ['comment' => 'x'])->assertOk();

        // IT requests go to the IT department when none is chosen.
        $it = Department::factory()->for($this->org)->create(['code' => 'IT']);
        $itId = $this->raise(['category' => 'it', 'sub_category' => 'printer_scanner', 'department_id' => null, 'priority' => 'urgent']);
        $this->assertSame($it->id, WorkRequest::query()->findOrFail($itId)->department_id);

        $this->actingAs($this->head);
        $this->spa()->getJson('/api/v1/requests/report')->assertOk()->assertJsonPath('data.by_status.cancelled', 1)->assertJsonPath('data.by_department.0.name', $this->works->name);
        $this->assertStringContainsString('Leaking tap', $this->spa()->get('/api/v1/requests?format=csv&status=all')->streamedContent());
        $this->actingAs($this->teacher);
        $this->spa()->get('/api/v1/requests?format=csv')->assertForbidden();
        $this->spa()->getJson('/api/v1/requests/report')->assertForbidden();
    }

    public function test_public_form_honeypot_rate_limit_and_tracker(): void
    {
        RateLimiter::clear('public-request:127.0.0.1');
        $this->getJson('/api/v1/public/requests/org1')->assertOk()->assertJsonPath('data.organization.code', 'ORG1')->assertJsonCount(2, 'data.departments');
        $this->getJson('/api/v1/public/requests/NOPE')->assertNotFound();
        $form = ['requester_name' => 'Parent', 'requester_contact' => '+91 98220 12345', 'department_id' => $this->works->id, 'work_description' => 'Gate light is off'];
        $this->postJson('/api/v1/public/requests/ORG1', [...$form, 'website' => 'spam'])->assertCreated()->assertJsonPath('data.request_code', null);
        $this->assertSame(0, WorkRequest::query()->count());
        $other = Organization::factory()->create();
        $foreign = Department::factory()->for($other)->create();
        $this->postJson('/api/v1/public/requests/ORG1', [...$form, 'department_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors('department_id');

        $code = $this->postJson('/api/v1/public/requests/ORG1', $form)->assertCreated()->json('data.request_code');
        $this->assertSame(['request.new'], $this->types($this->head));
        $this->postJson('/api/v1/public/requests/ORG1/track', ['code' => $code, 'contact' => '9999999999'])->assertNotFound();
        $this->postJson('/api/v1/public/requests/ORG1/track', ['code' => strtolower($code), 'contact' => '09822012345'])->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.seen_at', null)->assertJsonMissingPath('data.requester_name');

        $id = WorkRequest::query()->where('request_code', $code)->value('id');
        $this->actingAs($this->head);
        $this->spa()->getJson("/api/v1/requests/{$id}")->assertOk();
        $this->spa()->postJson("/api/v1/requests/{$id}/status", ['status' => 'completed', 'remark' => 'Bulb changed'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/public/requests/ORG1/track', ['code' => $code, 'contact' => '9822012345'])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completion_remarks', 'Bulb changed')
            ->assertJsonPath('data.timeline.1.status', 'completed')->assertJsonMissingPath('data.timeline.1.by');
        $this->assertNotNull($this->postJson('/api/v1/public/requests/ORG1/track', ['code' => $code, 'contact' => '9822012345'])->json('data.seen_at'));

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/public/requests/ORG1', $form)->assertCreated();
        }
        $this->postJson('/api/v1/public/requests/ORG1', $form)->assertStatus(429);
    }

    public function test_escalation_goes_to_the_head_then_the_organization(): void
    {
        $orgAdmin = $this->userWithRole($this->org, ['requests.manage']);
        $id = $this->raise(['priority' => 'urgent']);
        $this->artisan('erp:escalate-tickets')->assertSuccessful();
        $this->assertSame(['request.new'], $this->types($this->head));

        Carbon::setTestNow(now()->addHours(9));
        $this->artisan('erp:escalate-tickets')->assertSuccessful();
        $this->artisan('erp:escalate-tickets')->assertSuccessful();
        $this->assertSame(['request.new', 'sla.escalated'], $this->types($this->head));
        $this->assertSame([], $this->types($orgAdmin));
        $this->assertSame(1, WorkRequest::query()->findOrFail($id)->escalation_level);

        Carbon::setTestNow(now()->addHours(8));
        $this->artisan('erp:escalate-tickets')->assertSuccessful();
        $this->assertSame(['sla.escalated'], $this->types($orgAdmin));
        $this->assertSame(['request.new', 'sla.escalated'], $this->types($this->head));
        $this->assertSame(2, WorkRequest::query()->findOrFail($id)->escalation_level);
    }
}
