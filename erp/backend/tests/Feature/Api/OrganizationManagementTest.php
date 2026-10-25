<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function payload(array $override = []): array
    {
        return ['name' => 'New Trust', 'code' => 'NT', 'financial_year_start_month' => 4, ...$override];
    }

    public function test_super_admin_creates_an_organization_with_default_roles(): void
    {
        $this->setUpPermissions();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        $id = $this->spa()->postJson('/api/v1/organizations', $this->payload())->assertCreated()->json('data.id');

        $keys = Role::withoutGlobalScopes()->where('organization_id', $id)->pluck('key')->sort()->values()->all();
        $this->assertSame(['hod', 'org_admin', 'principal', 'staff'], $keys);
        $this->spa()->postJson('/api/v1/organizations', $this->payload(['name' => 'Again']))->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_organization_admin_edits_only_their_own_organization(): void
    {
        $this->setUpPermissions();
        $mine = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->actingAs($this->userWithRole($mine, ['organizations.view', 'organizations.edit']));

        $this->spa()->postJson('/api/v1/organizations', $this->payload())->assertForbidden();
        $this->spa()->putJson("/api/v1/organizations/{$mine->id}", $this->payload(['code' => $mine->code, 'name' => 'Renamed']))
            ->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->spa()->putJson("/api/v1/organizations/{$other->id}", $this->payload(['code' => $other->code]))->assertNotFound();
        $this->spa()->getJson("/api/v1/organizations/{$other->id}")->assertNotFound();
        $this->spa()->postJson("/api/v1/organizations/{$mine->id}/active", ['is_active' => false])->assertForbidden();

        $this->assertSame([$mine->id], $this->spa()->getJson('/api/v1/organizations')->assertOk()->json('data.*.id'));
    }

    public function test_super_admin_deactivates_an_organization_and_its_members_lose_it(): void
    {
        $this->setUpPermissions();
        $org = Organization::factory()->create();
        $member = $this->userWithRole($org, ['departments.view']);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->spa()->postJson("/api/v1/organizations/{$org->id}/active", ['is_active' => false])->assertOk();

        $this->actingAs($member);
        $this->spa()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.current_organization', null);
    }
}
