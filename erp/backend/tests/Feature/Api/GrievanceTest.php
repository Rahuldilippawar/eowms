<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grievance;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class GrievanceTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private User $hr;

    private User $staff;

    private User $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $dept = Department::factory()->for($this->org)->create();
        $this->hr = $this->userWithRole($this->org, ['grievances.manage']);
        $this->staff = $this->userWithRole($this->org, []);
        $this->helper = $this->userWithRole($this->org, []);
        Employee::factory()->in($dept)->create(['user_id' => $this->helper->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> */
    private function types(User $u): array
    {
        return UserNotification::withoutGlobalScopes()->where('user_id', $u->id)->orderBy('id')->pluck('type')->all();
    }

    public function test_anonymous_grievance_stays_anonymous_but_its_sender_follows_it(): void
    {
        $this->actingAs($this->staff);
        $this->spa()->postJson('/api/v1/grievances', ['description' => 'short'])->assertStatus(422);
        $id = $this->spa()->postJson('/api/v1/grievances', ['category' => 'Workplace', 'description' => 'The canteen water is not clean.', 'is_anonymous' => true])
            ->assertCreated()->assertJsonPath('data.is_mine', true)->assertJsonPath('data.submitted_by', null)->json('data.id');
        $g = Grievance::query()->findOrFail($id);
        $this->assertNull($g->submitted_by);
        $this->assertNotNull($g->submitter_hash);
        $this->assertSame(0, AuditLog::query()->where('module', 'grievances')->count());
        $this->spa()->getJson('/api/v1/grievances?mine=1')->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.submitter_hash');
        $this->spa()->postJson("/api/v1/grievances/{$id}/comments", ['comment' => 'It is the second floor cooler.'])->assertOk()->assertJsonPath('data.comments.0.by', null);
        $this->assertSame(['grievance.new', 'grievance.comment'], $this->types($this->hr));

        // Nobody else sees it, not even another staff member.
        $this->actingAs($this->helper);
        $this->spa()->getJson("/api/v1/grievances/{$id}")->assertNotFound();

        // The handler assigns and resolves; names of the sender never appear.
        $this->actingAs($this->hr);
        $this->spa()->getJson("/api/v1/grievances/{$id}")->assertOk()->assertJsonPath('data.submitted_by', null)->assertJsonPath('data.is_mine', false);
        $this->spa()->putJson("/api/v1/grievances/{$id}", ['status' => 'in_progress', 'assigned_to' => $this->helper->id])->assertOk();
        $this->assertSame(['grievance.assigned'], $this->types($this->helper));
        $this->actingAs($this->helper);
        $this->spa()->putJson("/api/v1/grievances/{$id}", ['status' => 'resolved', 'assigned_to' => $this->hr->id])->assertStatus(422);
        $this->spa()->putJson("/api/v1/grievances/{$id}", ['status' => 'resolved'])->assertStatus(422)->assertJsonValidationErrors('resolution');
        $this->spa()->putJson("/api/v1/grievances/{$id}", ['status' => 'resolved', 'resolution' => 'Filter replaced.'])->assertOk()->assertJsonPath('data.status', 'resolved');

        $this->actingAs($this->staff);
        $this->spa()->postJson("/api/v1/grievances/{$id}/reopen", ['reason' => 'Still dirty.'])->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.reopened_count', 1);
        $this->assertSame(0, AuditLog::query()->where('module', 'grievances')->where('user_id', $this->staff->id)->count());
    }

    public function test_named_grievance_notifies_its_sender_and_escalates_once(): void
    {
        $this->actingAs($this->staff);
        $id = $this->spa()->postJson('/api/v1/grievances', ['description' => 'Salary slip for September is missing.'])->assertCreated()->json('data.id');
        $this->actingAs($this->hr);
        $this->spa()->getJson('/api/v1/grievances')->assertJsonPath('data.0.submitted_by', $this->staff->name)->assertJsonPath('meta.is_handler', true);
        $this->spa()->postJson("/api/v1/grievances/{$id}/comments", ['comment' => 'Looking into it.'])->assertOk();
        $this->spa()->putJson("/api/v1/grievances/{$id}", ['status' => 'in_progress'])->assertOk();
        $this->assertSame(['grievance.reply', 'grievance.status'], $this->types($this->staff));

        Carbon::setTestNow(now()->addDays(8));
        $this->artisan('erp:escalate-tickets')->assertSuccessful();
        $this->artisan('erp:escalate-tickets')->assertSuccessful();
        $this->assertSame(['grievance.new', 'sla.escalated'], $this->types($this->hr));
        $this->spa()->getJson('/api/v1/grievances?status=overdue')->assertJsonCount(1, 'data')->assertJsonPath('data.0.escalated', true);
    }
}
