<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_only_own_notifications_in_the_current_organization(): void
    {
        $this->setUpPermissions();
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $user = $this->userWithRole($org, []);
        $someoneElse = $this->userWithRole($org, []);
        $make = fn ($u, $o, $type = 'task.assigned') => UserNotification::withoutGlobalScopes()->create(['organization_id' => $o->id, 'user_id' => $u->id, 'type' => $type, 'data' => ['title' => 'x']]);
        $mine = $make($user, $org);
        $make($user, $org, 'task.comment');
        $make($user, $otherOrg);
        $theirs = $make($someoneElse, $org);

        $this->actingAs($user);
        $this->spa()->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('unread', 2)->assertJsonPath('data.0.type', 'task.comment');
        $this->spa()->postJson("/api/v1/notifications/{$theirs->id}/read")->assertNotFound();
        $this->spa()->postJson("/api/v1/notifications/{$mine->id}/read")->assertOk()->assertJsonPath('data.unread', 1);
        $this->spa()->getJson('/api/v1/notifications?unread=1')->assertJsonCount(1, 'data');
        $this->spa()->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.unread', 0);
        $this->assertNull($theirs->fresh()->read_at);
        $this->assertSame(1, UserNotification::withoutGlobalScopes()->where('user_id', $user->id)->whereNull('read_at')->count(), 'other organization untouched');
    }

    public function test_guests_get_401(): void
    {
        $this->spa()->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    }
}
