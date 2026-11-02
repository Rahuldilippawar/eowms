<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class AboutTvSystemTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00', 'Asia/Kolkata'));
        $this->org = Organization::factory()->create(['name' => 'Shivaji Trust']);
        $this->admin = $this->userWithRole($this->org, ['organizations.edit']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_about_page_is_read_by_everyone_and_edited_by_admins(): void
    {
        $this->actingAs($this->admin);
        $id = $this->spa()->post('/api/v1/about', ['kind' => 'sadguru', 'title' => 'Sadguru', 'body' => 'Teachings', 'photo' => UploadedFile::fake()->image('g.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.has_photo', true)->json('data.id');
        $this->spa()->postJson('/api/v1/about', ['kind' => 'contributor', 'title' => 'Hidden helper', 'is_active' => false])->assertCreated();
        $this->spa()->postJson('/api/v1/about', ['kind' => 'nobody', 'title' => 'X'])->assertStatus(422);

        $staff = $this->userWithRole($this->org, ['departments.view']);
        $this->actingAs($staff);
        $this->spa()->getJson('/api/v1/about')->assertOk()->assertJsonPath('meta.can_manage', false)->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.photo_path');
        $this->spa()->get("/api/v1/about/{$id}/photo")->assertOk();
        $this->spa()->postJson("/api/v1/about/{$id}", ['kind' => 'sadguru', 'title' => 'Changed'])->assertForbidden();

        $this->actingAs($this->userWithRole(Organization::factory()->create(), ['organizations.edit']));
        $this->spa()->get("/api/v1/about/{$id}/photo")->assertNotFound();
        $this->spa()->deleteJson("/api/v1/about/{$id}")->assertNotFound();
    }

    public function test_a_screen_link_shows_counts_and_todays_birthdays_only(): void
    {
        $dept = Department::factory()->for($this->org)->create();
        $asha = Employee::factory()->in($dept)->create(['name' => 'Asha', 'date_of_birth' => '1990-07-10', 'joining_date' => '2020-07-10', 'phone' => '9999999999']);
        Employee::factory()->in($dept)->create(['name' => 'Ravi', 'date_of_birth' => '1991-01-02']);
        AttendanceRecord::query()->forceCreate(['organization_id' => $this->org->id, 'employee_id' => $asha->id, 'department_id' => $dept->id,
            'work_date' => '2026-07-10', 'status' => 'present', 'source' => 'manual']);

        $this->actingAs($this->userWithRole($this->org, ['organizations.edit'], PermissionScope::Departments, departmentIds: [$dept->id]));
        $this->spa()->postJson('/api/v1/tv-displays', ['name' => 'Office screen'])->assertForbidden();
        $this->actingAs($this->admin);
        $url = $this->spa()->postJson('/api/v1/tv-displays', ['name' => 'Office screen'])->assertCreated()->json('data.url');
        $this->spa()->getJson('/api/v1/tv-displays')->assertOk()->assertJsonMissingPath('data.0.token_hash')->assertJsonMissingPath('data.0.url');

        auth()->forgetGuards();
        $token = substr($url, 4);
        $data = $this->getJson("/api/v1/public/tv/{$token}")->assertOk()->json('data');
        $this->assertSame(['Shivaji Trust', 2, 1, ['Asha'], ['Asha']], [$data['organization'], $data['staff'], $data['present_today'], $data['birthdays'], $data['anniversaries']]);
        $this->assertStringNotContainsString('9999999999', json_encode($data));
        $this->getJson('/api/v1/public/tv/'.str_repeat('a', 40))->assertNotFound();

        $this->actingAs($this->admin);
        $id = $this->spa()->getJson('/api/v1/tv-displays')->json('data.0.id');
        $this->spa()->deleteJson("/api/v1/tv-displays/{$id}")->assertNoContent();
        $this->getJson("/api/v1/public/tv/{$token}")->assertNotFound();
    }

    public function test_system_status_and_database_update_are_for_super_admins(): void
    {
        $this->actingAs($this->admin);
        $this->spa()->getJson('/api/v1/admin/system')->assertForbidden();
        $this->spa()->postJson('/api/v1/me/onboarding-seen')->assertOk()->assertJsonPath('data.onboarding_seen', true);

        $super = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($super);
        $this->spa()->getJson('/api/v1/admin/system')->assertOk()->assertJsonPath('data.pending_migrations', [])
            ->assertJsonPath('data.checks.scheduler', false)->assertJsonStructure(['data' => ['php', 'laravel', 'database', 'checks' => ['debug_off', 'https', 'mail', 'push']]]);
        $this->spa()->postJson('/api/v1/admin/system/migrate', ['password' => 'wrong', 'confirmation' => 'MIGRATE'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->spa()->postJson('/api/v1/admin/system/migrate', ['password' => 'password', 'confirmation' => 'MIGRATE'])->assertOk()->assertJsonPath('data.ran', [])->assertJsonPath('data.safety_backup', null);
    }
}
