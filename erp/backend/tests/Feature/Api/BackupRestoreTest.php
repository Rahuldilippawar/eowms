<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Services\Backup\DatabaseBackup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A restore replaces tables (DDL commits any open transaction), so this class starts and ends with
 * a freshly migrated database instead of the usual rolled-back transaction.
 */
class BackupRestoreTest extends TestCase
{
    use BuildsTenants;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        $this->setUpPermissions();
        $this->dir = storage_path('framework/testing/backups-'.uniqid());
        config(['erp.backups_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        $this->artisan('migrate:fresh');
        parent::tearDown();
    }

    public function test_a_super_admin_backs_up_and_restores_the_whole_database(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->create(['is_super_admin' => true, 'name' => "Admin 'quoted'\nline"]);
        $this->userWithRole($org, ['organizations.edit'], user: $admin);
        Department::factory()->for($org)->create(['name' => 'Before; with ; semicolons']);

        $this->actingAs($this->userWithRole($org, ['organizations.edit']));
        $this->spa()->postJson('/api/v1/backups')->assertForbidden();
        $this->spa()->getJson('/api/v1/backups')->assertForbidden();

        $this->actingAs($admin);
        $name = $this->spa()->postJson('/api/v1/backups')->assertCreated()->json('data.name');
        $this->assertMatchesRegularExpression('/^erp-backup-\d{8}-\d{6}-manual\.sql\.gz$/', $name);
        $this->assertSame([$name], array_column($this->spa()->getJson('/api/v1/backups')->assertOk()->json('data'), 'name'));
        $this->assertSame('application/gzip', $this->spa()->get("/api/v1/backups/{$name}")->assertOk()->headers->get('Content-Type'));
        $this->spa()->get('/api/v1/backups/..%2F..%2F.env')->assertNotFound();

        Department::factory()->for($org)->create(['name' => 'Added after']);
        $this->spa()->postJson("/api/v1/backups/{$name}/restore", ['password' => 'wrong', 'confirmation' => 'RESTORE'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->spa()->postJson("/api/v1/backups/{$name}/restore", ['password' => 'password', 'confirmation' => 'restore'])->assertStatus(422)->assertJsonValidationErrors('confirmation');
        $safety = $this->spa()->postJson("/api/v1/backups/{$name}/restore", ['password' => 'password', 'confirmation' => 'RESTORE'])->assertOk()->json('data.safety_backup');

        $this->assertSame(['Before; with ; semicolons'], Department::withoutGlobalScopes()->pluck('name')->all());
        $this->assertSame("Admin 'quoted'\nline", $admin->fresh()->name);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertStringEndsWith('-pre_restore.sql.gz', $safety);

        // Uploads must be backups of this ERP; deleting removes the file.
        $this->spa()->post('/api/v1/backups/upload', ['file' => UploadedFile::fake()->createWithContent('x.sql.gz', gzencode("DROP DATABASE erp;\n"))], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $copy = $this->spa()->post('/api/v1/backups/upload', ['file' => UploadedFile::fake()->createWithContent('mine.sql.gz', file_get_contents(app(DatabaseBackup::class)->path($name)))], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.name');
        $this->assertStringContainsString('-uploaded', $copy);
        $this->spa()->deleteJson("/api/v1/backups/{$copy}")->assertNoContent();
        $this->assertCount(2, $this->spa()->getJson('/api/v1/backups')->json('data'));
    }
}
