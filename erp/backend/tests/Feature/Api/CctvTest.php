<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class CctvTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->manager = $this->userWithRole($this->org, ['cctv.view', 'cctv.manage']);
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_recorders_cameras_and_the_daily_check(): void
    {
        $this->actingAs($this->manager);
        $dvr = $this->spa()->postJson('/api/v1/cctv/dvrs', ['name' => 'Gate DVR', 'channel_capacity' => 2, 'storage_tb' => 2])->assertCreated()->json('data.id');
        $this->spa()->postJson("/api/v1/cctv/dvrs/{$dvr}/cameras", ['name' => 'Cam-01', 'camera_type' => 'bullet', 'location' => 'Gate'])->assertOk();
        $this->spa()->postJson("/api/v1/cctv/dvrs/{$dvr}/cameras", ['name' => 'Cam-01', 'camera_type' => 'dome'])->assertStatus(422)->assertJsonValidationErrors('name');
        $cams = $this->spa()->postJson("/api/v1/cctv/dvrs/{$dvr}/cameras", ['name' => 'Cam-02', 'camera_type' => 'dome'])->assertOk()->json('data.cameras');
        $this->spa()->postJson("/api/v1/cctv/dvrs/{$dvr}/cameras", ['name' => 'Cam-03', 'camera_type' => 'ptz'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->spa()->putJson("/api/v1/cctv/dvrs/{$dvr}", ['name' => 'Gate DVR', 'channel_capacity' => 1])->assertStatus(422)->assertJsonValidationErrors('channel_capacity');
        $this->spa()->getJson('/api/v1/cctv/dashboard')->assertJsonPath('data.dvrs.0.late', true)->assertJsonPath('data.dvrs.0.last_checkup', null);

        // The guard checks the recorder: one camera down; the managers hear once.
        $guard = $this->userWithRole($this->org, ['cctv.check']);
        $this->actingAs($guard);
        $sheet = $this->spa()->getJson("/api/v1/cctv/dvrs/{$dvr}/checkup")->assertOk()->assertJsonPath('data.saved', false)->json('data.cameras');
        $this->assertSame(['working', 'working'], array_column($sheet, 'status'));
        $this->spa()->getJson("/api/v1/cctv/dvrs/{$dvr}/checkup?date=2026-07-11")->assertStatus(422);
        $body = ['date' => '2026-07-10', 'storage_used_percent' => 82.5, 'footage_days' => 21, 'backup_done' => false, 'cameras' => [
            ['camera_id' => $cams[0]['id'], 'status' => 'working'], ['camera_id' => $cams[1]['id'], 'status' => 'not_working', 'remarks' => 'No picture'],
        ]];
        $this->spa()->postJson("/api/v1/cctv/dvrs/{$dvr}/checkup", $body)->assertOk()->assertJsonPath('data.saved', true)->assertJsonPath('data.cameras.1.status', 'not_working');
        $this->spa()->postJson("/api/v1/cctv/dvrs/{$dvr}/checkup", $body + ['remarks' => 'Saved again'])->assertOk();
        $this->assertSame(['cctv.cameras_down'], UserNotification::withoutGlobalScopes()->where('user_id', $this->manager->id)->pluck('type')->all());
        $this->spa()->postJson('/api/v1/cctv/dvrs', ['name' => 'X'])->assertForbidden();
        $this->spa()->postJson("/api/v1/cctv/dvrs/{$dvr}/checkup", ['date' => '2026-07-09', 'backup_done' => true, 'cameras' => [['camera_id' => 99999, 'status' => 'working']]])
            ->assertStatus(422)->assertJsonValidationErrors('cameras.0.camera_id');
        // An earlier day entered late does not become the latest check.
        $this->spa()->postJson("/api/v1/cctv/dvrs/{$dvr}/checkup", ['date' => '2026-07-08', 'backup_done' => true, 'cameras' => []])->assertOk();

        $this->actingAs($this->manager);
        $this->spa()->getJson('/api/v1/cctv/dashboard')->assertJsonPath('data.dvrs.0.late', false)->assertJsonPath('data.dvrs.0.last_checkup.date', '2026-07-10')
            ->assertJsonPath('data.cameras.not_working', 1)->assertJsonPath('data.checked_today', 1);
        $this->spa()->getJson("/api/v1/cctv/dvrs/{$dvr}")->assertJsonCount(2, 'data.checkups')->assertJsonPath('data.checkups.0.not_working', 1);
        $this->spa()->getJson('/api/v1/cctv/report?period=weekly')->assertJsonPath('data.dvrs.0.checkups', 2)->assertJsonPath('data.dvrs.0.compliance', 29)
            ->assertJsonPath('data.dvrs.0.backups', 1)->assertJsonPath('data.dvrs.0.max_storage', 82.5);

        $this->actingAs($this->userWithRole($this->org, []));
        $this->spa()->getJson('/api/v1/cctv/dashboard')->assertForbidden();
    }

    public function test_import_creates_recorders_and_checks_every_row(): void
    {
        $this->actingAs($this->manager);
        $header = 'dvr_name,dvr_location,channel_capacity,storage_capacity_tb,installed_date,camera_name,camera_location,camera_type';
        $bad = UploadedFile::fake()->createWithContent('c.csv', "{$header}\nHostel DVR,,8,2,,Cam-01,,fisheye\nHostel DVR,,8,2,31/02/2026,Cam-02,,dome\nHostel DVR,,8,2,,Cam-02,,dome\n");
        $rows = collect($this->spa()->post('/api/v1/cctv/import/preview', ['file' => $bad], ['Accept' => 'application/json'])->assertOk()->json('data.rows'));
        $this->assertArrayHasKey('camera_type', $rows[0]['errors']);
        $this->assertArrayHasKey('installed_date', $rows[1]['errors']);
        $this->assertArrayHasKey('camera_name', $rows[2]['errors']);
        $good = UploadedFile::fake()->createWithContent('c.csv', "{$header}\nHostel DVR,Office,8,2,15/01/2026,Cam-01,Gate,PTZ\nHostel DVR,Office,8,2,15/01/2026,Cam-02,Mess,\n");
        $this->spa()->post('/api/v1/cctv/import', ['file' => $good], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.imported', 2)->assertJsonPath('data.dvrs_created', 1);
        $dvr = $this->spa()->getJson('/api/v1/cctv/dashboard')->assertJsonPath('data.dvrs.0.cameras_count', 2)->json('data.dvrs.0.id');
        $this->spa()->getJson("/api/v1/cctv/dvrs/{$dvr}")->assertJsonPath('data.installed_date', '2026-01-15')->assertJsonPath('data.cameras.0.camera_type', 'ptz');
    }
}
