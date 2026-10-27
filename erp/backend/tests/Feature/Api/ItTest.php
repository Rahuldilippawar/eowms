<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class ItTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private User $it;

    private User $assistant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->it = $this->userWithRole($this->org, ['it.view', 'it.manage']);
        $this->assistant = $this->userWithRole($this->org, []);
        Employee::factory()->in(Department::factory()->for($this->org)->create())->create(['user_id' => $this->assistant->id]);
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: int, 1: int} two lab ids, the first kept by the assistant */
    private function labs(): array
    {
        $this->actingAs($this->it);
        $a = $this->spa()->postJson('/api/v1/it/labs', ['name' => 'Lab A', 'capacity' => 30, 'assistant_user_id' => $this->assistant->id])->assertCreated()->json('data.id');
        $b = $this->spa()->postJson('/api/v1/it/labs', ['name' => 'Lab B'])->assertCreated()->json('data.id');

        return [$a, $b];
    }

    public function test_assets_tickets_transfers_and_what_the_lab_assistant_may_do(): void
    {
        [$labA, $labB] = $this->labs();
        $pc = $this->spa()->postJson('/api/v1/it/assets', ['lab_id' => $labA, 'asset_type' => 'cpu', 'brand' => 'Dell', 'workstation_no' => 'PC-01', 'warranty_expiry' => '2026-07-25'])
            ->assertCreated()->assertJsonPath('data.asset_tag', 'CPU-26-0001')->json('data.id');
        $this->spa()->postJson('/api/v1/it/assets', ['lab_id' => $labB, 'asset_type' => 'cpu'])->assertCreated()->assertJsonPath('data.asset_tag', 'CPU-26-0002');
        $board = $this->spa()->postJson('/api/v1/it/assets', ['lab_id' => $labA, 'asset_type' => 'smartboard'])->assertCreated()->assertJsonPath('data.asset_tag', 'SB-26-0001')->json('data.id');

        // The assistant sees and keeps only their own lab.
        $this->actingAs($this->assistant);
        $this->spa()->getJson('/api/v1/auth/me')->assertJsonPath('data.it_lab_assistant', true);
        $this->spa()->getJson('/api/v1/it/assets')->assertJsonCount(2, 'data');
        $this->spa()->postJson('/api/v1/it/assets', ['lab_id' => $labB, 'asset_type' => 'mouse'])->assertForbidden();
        $this->spa()->postJson("/api/v1/it/assets/{$board}/status", ['power_status' => 'on'])->assertOk()->assertJsonPath('data.power_status', 'on');
        $this->spa()->postJson("/api/v1/it/assets/{$pc}/transfer", ['to_lab_id' => $labB])->assertForbidden();
        $this->spa()->getJson('/api/v1/it/dashboard')->assertJsonPath('data.smartboards.on', 1)->assertJsonPath('data.expiring.0.asset_tag', 'CPU-26-0001');

        // Anyone signed in finds the asset by its tag and reports a problem; IT and the assistant hear.
        $teacher = $this->userWithRole($this->org, []);
        $this->actingAs($teacher);
        $this->spa()->getJson('/api/v1/it/assets')->assertForbidden();
        $this->spa()->getJson('/api/v1/it/lookup?tag=cpu-26-0001')->assertOk()->assertJsonPath('data.lab', 'Lab A');
        $this->spa()->getJson('/api/v1/it/lookup?tag=XX-1')->assertNotFound();
        $ticket = $this->spa()->postJson("/api/v1/it/assets/{$pc}/report", ['issue_description' => 'Does not start'])->assertCreated()->json('data.id');
        $this->assertSame(['it.ticket_new'], UserNotification::withoutGlobalScopes()->where('user_id', $this->it->id)->pluck('type')->all());
        $this->assertSame(['it.ticket_new'], UserNotification::withoutGlobalScopes()->where('user_id', $this->assistant->id)->pluck('type')->all());

        $this->actingAs($this->it);
        $this->spa()->getJson("/api/v1/it/assets/{$pc}")->assertJsonPath('data.status', 'faulty')->assertJsonPath('data.open_tickets', 1);
        $this->spa()->postJson("/api/v1/it/tickets/{$ticket}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->spa()->getJson("/api/v1/it/assets/{$pc}")->assertJsonPath('data.status', 'under_repair');
        $this->spa()->postJson("/api/v1/it/tickets/{$ticket}/start")->assertStatus(422);
        $this->spa()->postJson("/api/v1/it/assets/{$pc}/transfer", ['to_lab_id' => $labB, 'remarks' => 'Spare lab'])->assertOk()->assertJsonPath('data.lab.name', 'Lab B')
            ->assertJsonPath('data.transfers.0.from', 'Lab A');
        $this->spa()->postJson("/api/v1/it/tickets/{$ticket}/resolve", ['resolution_notes' => 'Replaced the power supply'])->assertOk()->assertJsonPath('data.lab', 'Lab B');
        $this->spa()->getJson("/api/v1/it/assets/{$pc}")->assertJsonPath('data.status', 'working')->assertJsonPath('data.open_tickets', 0);
        $this->assertSame(['it.ticket_resolved'], UserNotification::withoutGlobalScopes()->where('user_id', $teacher->id)->pluck('type')->all());
        $this->spa()->getJson('/api/v1/it/tickets?status=all')->assertJsonCount(1, 'data');
        $this->spa()->getJson('/api/v1/it/smartboards')->assertJsonCount(1, 'data');

        // A scrapped asset takes no more reports.
        $this->spa()->postJson("/api/v1/it/assets/{$board}/status", ['status' => 'scrapped'])->assertOk();
        $this->actingAs($teacher);
        $this->spa()->postJson("/api/v1/it/assets/{$board}/report", ['issue_description' => 'Screen cracked'])->assertStatus(422);
        $this->spa()->getJson('/api/v1/my/it-tickets')->assertJsonCount(1, 'data')->assertJsonPath('data.0.resolution_notes', 'Replaced the power supply');
    }

    public function test_import_creates_missing_labs_and_checks_every_row(): void
    {
        $this->labs();
        $header = 'lab_name,location,capacity,workstation_no,asset_type,brand,model,serial_no,purchase_date,purchase_cost,warranty_expiry,os_license,license_expiry';
        $bad = UploadedFile::fake()->createWithContent('i.csv', "{$header}\nLab A,,,PC-01,toaster,,,,,,,,\nLab A,,,PC-01,cpu,,,,31/02/2026,,,,\n");
        $rows = collect($this->spa()->post('/api/v1/it/import/preview', ['file' => $bad], ['Accept' => 'application/json'])->assertOk()->json('data.rows'));
        $this->assertArrayHasKey('asset_type', $rows[0]['errors']);
        $this->assertArrayHasKey('purchase_date', $rows[1]['errors']);
        $good = UploadedFile::fake()->createWithContent('i.csv', "{$header}\nLab A,,,PC-02,CPU,HP,ProDesk,SN1,15/01/2026,32000,2029-01-15,Windows 11,2027-01-15\nLab C,1st floor,20,PC-01,monitor,HP,P22,SN2,,,,,\n");
        $this->spa()->post('/api/v1/it/import', ['file' => $good], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.imported', 2)->assertJsonPath('data.labs_created', 1);
        $this->spa()->getJson('/api/v1/it/assets?search=SN1')->assertJsonPath('data.0.asset_tag', 'CPU-26-0001')->assertJsonPath('data.0.lab.name', 'Lab A');
        $this->spa()->getJson('/api/v1/it/labs')->assertJsonCount(3, 'data');
        $this->assertStringContainsString('SN2', $this->spa()->get('/api/v1/it/assets?format=csv')->streamedContent());
    }
}
