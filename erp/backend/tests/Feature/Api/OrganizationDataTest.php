<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class OrganizationDataTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->dir = storage_path('framework/testing/backups-'.uniqid());
        config(['erp.backups_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_clearing_keeps_structure_accounts_and_other_organizations(): void
    {
        $org = Organization::factory()->create(['name' => 'Shivaji Trust']);
        $other = Organization::factory()->create();
        $dept = Department::factory()->for($org)->create();
        $admin = $this->userWithRole($org, ['organizations.edit']);
        $asha = Employee::factory()->in($dept)->create();
        $ravi = Employee::factory()->in($dept)->create();
        $kept = Employee::factory()->in(Department::factory()->for($other)->create())->create();
        $entry = WorkEntry::query()->forceCreate(['organization_id' => $org->id, 'employee_id' => $asha->id, 'department_id' => $dept->id,
            'work_date' => '2026-07-01', 'title' => 'Repair', 'status' => 'completed', 'created_by' => $admin->id]);
        DB::table('work_entry_team')->insert(['work_entry_id' => $entry->id, 'employee_id' => $ravi->id]);
        AuditLog::create(['organization_id' => $org->id, 'user_id' => $admin->id, 'module' => 'letters', 'action' => 'create']);

        $this->actingAs($this->userWithRole($org, ['organizations.edit'], PermissionScope::Departments, departmentIds: [$dept->id]));
        $this->spa()->postJson('/api/v1/organization/clear', ['password' => 'password', 'confirmation' => 'Shivaji Trust'])->assertForbidden();

        $this->actingAs($admin);
        $this->spa()->postJson('/api/v1/organization/clear', ['password' => 'password', 'confirmation' => 'Shivaji'])->assertStatus(422)->assertJsonValidationErrors('confirmation');
        $res = $this->spa()->postJson('/api/v1/organization/clear', ['password' => 'password', 'confirmation' => 'Shivaji Trust'])->assertOk()->json('data');

        $this->assertSame(2, $res['deleted']['employees']);
        $this->assertStringEndsWith('-pre_clear.sql.gz', $res['safety_backup']);
        $this->assertFileExists($this->dir.'/'.$res['safety_backup']);
        $this->assertSame(0, DB::table('work_entries')->count());
        $this->assertSame(0, DB::table('work_entry_team')->count());
        $this->assertSame([$kept->id], DB::table('employees')->pluck('id')->all());
        $this->assertTrue(DB::table('departments')->where('id', $dept->id)->exists());
        $this->assertTrue(User::query()->whereKey($admin->id)->exists());
        $this->assertTrue(AuditLog::query()->where(['module' => 'letters', 'organization_id' => $org->id])->exists());
        $this->assertTrue(AuditLog::query()->where(['module' => 'backup', 'action' => 'organization_clear'])->exists());
    }

    public function test_the_export_holds_the_organizations_rows_without_secrets(): void
    {
        $org = Organization::factory()->create(['name' => 'Shivaji Trust']);
        $other = Organization::factory()->create();
        $dept = Department::factory()->for($org)->create();
        Employee::factory()->in($dept)->create(['name' => 'Asha Patil']);
        Employee::factory()->in(Department::factory()->for($other)->create())->create(['name' => 'Someone Else']);
        $admin = $this->userWithRole($org, ['organizations.edit']);
        DB::table('grievances')->insert(['organization_id' => $org->id, 'description' => 'Anonymous complaint text', 'is_anonymous' => true,
            'submitter_hash' => 'secret-hash-value', 'status' => 'open', 'sla_deadline' => '2026-07-10', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin);
        $json = json_decode($this->spa()->get('/api/v1/organization/export')->assertOk()->streamedContent(), true);
        $this->assertSame('Shivaji Trust', $json['organization']['name']);
        $this->assertSame(['Asha Patil'], array_column($json['tables']['employees'], 'name'));
        $this->assertSame([$admin->email], array_column($json['tables']['users'], 'email'));
        $this->assertArrayNotHasKey('password', $json['tables']['users'][0]);
        $this->assertArrayNotHasKey('submitter_hash', $json['tables']['grievances'][0]);
        $this->assertArrayNotHasKey('audit_logs', $json['tables']);
    }
}
