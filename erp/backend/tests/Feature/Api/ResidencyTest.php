<?php

namespace Tests\Feature\Api;

use App\Models\Building;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MaintenanceLocation;
use App\Models\Organization;
use App\Models\ResidencyOccupant;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class ResidencyTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private User $estate;

    private User $staffA;

    private User $staffB;

    private MaintenanceLocation $r101;

    private MaintenanceLocation $r102;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $dept = Department::factory()->for($this->org)->create();
        $this->estate = $this->userWithRole($this->org, ['residency.view', 'residency.manage']);
        $this->staffA = $this->userWithRole($this->org, []);
        $this->staffB = $this->userWithRole($this->org, []);
        Employee::factory()->in($dept)->create(['user_id' => $this->staffA->id, 'name' => 'Amol']);
        Employee::factory()->in($dept)->create(['user_id' => $this->staffB->id, 'name' => 'Vikas']);
        $building = Building::create(['organization_id' => $this->org->id, 'name' => 'Quarters A']);
        $this->r101 = MaintenanceLocation::create(['organization_id' => $this->org->id, 'building_id' => $building->id, 'name' => 'Q-101', 'location_code' => 'Q101', 'capacity' => 3, 'is_residential' => true]);
        $this->r102 = MaintenanceLocation::create(['organization_id' => $this->org->id, 'building_id' => $building->id, 'name' => 'Q-102', 'location_code' => 'Q102', 'capacity' => 4, 'is_residential' => true]);
    }

    /** @return list<string> */
    private function types(User $u): array
    {
        return UserNotification::withoutGlobalScopes()->where('user_id', $u->id)->orderBy('id')->pluck('type')->all();
    }

    public function test_request_allot_family_share_bill_transfer_and_vacate(): void
    {
        // The staff member asks for housing; the estate office approves with a room.
        $this->actingAs($this->staffA);
        $this->spa()->postJson('/api/v1/my/residency/requests', ['family_size' => 2, 'reason' => 'Transferred here'])->assertCreated()->assertJsonPath('data.requests.0.status', 'pending');
        $this->spa()->postJson('/api/v1/my/residency/requests', ['family_size' => 2])->assertStatus(422);
        $this->spa()->getJson('/api/v1/residency/rooms')->assertForbidden();
        $this->assertSame(['residency.request_new'], $this->types($this->estate));

        $this->actingAs($this->estate);
        $request = $this->spa()->getJson('/api/v1/residency/requests')->assertJsonCount(1, 'data')->json('data.0.id');
        $this->spa()->postJson("/api/v1/residency/requests/{$request}/decide", ['decision' => 'approve'])->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->spa()->postJson("/api/v1/residency/requests/{$request}/decide", ['decision' => 'approve', 'location_id' => $this->r101->id])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertContains('residency.request_approved', $this->types($this->staffA));
        $a = ResidencyOccupant::query()->where('user_id', $this->staffA->id)->value('id');

        // Family joins; a second staff member shares the room; the room is now full.
        $this->spa()->postJson("/api/v1/residency/rooms/{$this->r101->id}/occupants", ['occupant_type' => 'family', 'family_of_id' => $a, 'name' => 'Seema', 'relation' => 'Wife'])->assertOk();
        $this->spa()->postJson("/api/v1/residency/rooms/{$this->r101->id}/occupants", ['occupant_type' => 'staff', 'user_id' => $this->staffA->id])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->spa()->postJson("/api/v1/residency/rooms/{$this->r101->id}/occupants", ['occupant_type' => 'staff', 'user_id' => $this->staffB->id])->assertOk()->assertJsonCount(3, 'data.occupants');
        $this->spa()->postJson("/api/v1/residency/rooms/{$this->r101->id}/occupants", ['occupant_type' => 'family', 'family_of_id' => $a, 'name' => 'X'])->assertStatus(422)->assertJsonValidationErrors('location_id');

        // The bill is split between the two staff members, not the family.
        $bill = $this->spa()->postJson("/api/v1/residency/rooms/{$this->r101->id}/bills", ['billing_month' => '2026-07', 'room_rent' => 1000, 'electricity_bill' => 501])
            ->assertOk()->json('data.bills.0');
        $this->assertEquals([2, 1501, [750.5, 750.5]], [$bill['billing_unit_count'], $bill['total_amount'], array_column($bill['shares'], 'amount_due')]);
        $this->spa()->postJson("/api/v1/residency/rooms/{$this->r101->id}/bills", ['billing_month' => '2026-07', 'room_rent' => 10])->assertStatus(422);
        $this->spa()->postJson("/api/v1/residency/bill-shares/{$bill['shares'][0]['id']}/paid", ['paid' => true])->assertOk();
        $this->spa()->getJson('/api/v1/residency/bills?month=2026-07')->assertJsonPath('data.total', 1501)->assertJsonPath('data.pending', 750.5);
        $this->actingAs($this->staffA);
        $this->spa()->getJson('/api/v1/my/residency')->assertJsonPath('data.home.room', 'Q-101')->assertJsonCount(1, 'data.home.family')->assertJsonPath('data.bills.0.amount_due', 750.5);

        // The household moves together; then B moves out.
        $this->actingAs($this->estate);
        $this->spa()->postJson("/api/v1/residency/occupants/{$a}/transfer", ['to_location_id' => $this->r102->id, 'reason' => 'More space'])->assertOk()->assertJsonCount(2, 'data.occupants');
        $this->assertSame(2, ResidencyOccupant::query()->where('location_id', $this->r102->id)->where('status', 'active')->count());
        $b = ResidencyOccupant::query()->where('user_id', $this->staffB->id)->value('id');
        $this->spa()->postJson("/api/v1/residency/occupants/{$b}/vacate", ['remark' => 'Retired'])->assertOk();
        $this->spa()->postJson("/api/v1/residency/occupants/{$b}/vacate")->assertStatus(422);

        $this->spa()->postJson("/api/v1/residency/rooms/{$this->r102->id}/items", ['item_name' => 'Ceiling fan', 'ownership' => 'institution', 'quantity' => 2])->assertOk()->assertJsonCount(1, 'data.items');
        $this->spa()->postJson("/api/v1/residency/rooms/{$this->r102->id}/inspections", ['inspection_date' => now()->toDateString(), 'condition_rating' => 'good'])->assertOk();
        $this->spa()->postJson("/api/v1/residency/rooms/{$this->r102->id}/guests", ['guest_name' => 'Uncle', 'host_occupant_id' => $a, 'arrival_date' => now()->toDateString()])->assertOk();
        $this->spa()->getJson('/api/v1/residency/analytics')->assertJsonPath('data.rooms', 2)->assertJsonPath('data.occupied_rooms', 1)->assertJsonPath('data.occupancy_rate', 50)
            ->assertJsonPath('data.guests_now', 1)->assertJsonPath('data.staff_housed', 1);
        $this->spa()->getJson('/api/v1/residency/directory')->assertJsonCount(1, 'data.resident')->assertJsonPath('data.resident.0.room', 'Q-102')->assertJsonCount(1, 'data.non_resident')->assertJsonPath('data.non_resident.0.name', 'Vikas');
    }

    public function test_import_links_by_email_leaves_others_unlinked_and_rolls_back(): void
    {
        $this->actingAs($this->estate);
        $header = 'building_name,room_name,occupant_type,name,staff_email,relation,moved_in_date';
        $bad = UploadedFile::fake()->createWithContent('r.csv', "{$header}\nNew Block,N-1,family,Seema,,Wife,\nNew Block,N-1,boss,X,,,\nNew Block,N-2,staff,Y,nobody@example.com,,31/02/2026\n");
        $rows = collect($this->spa()->post('/api/v1/residency/import/preview', ['file' => $bad], ['Accept' => 'application/json'])->assertOk()->json('data.rows'));
        $this->assertArrayHasKey('occupant_type', $rows[0]['errors']);
        $this->assertArrayHasKey('occupant_type', $rows[1]['errors']);
        $this->assertEqualsCanonicalizing(['staff_email', 'moved_in_date'], array_keys($rows[2]['errors']));

        $good = UploadedFile::fake()->createWithContent('r.csv', "{$header}\nNew Block,N-1,staff,Amol,{$this->staffA->email},,2026-01-15\nNew Block,N-1,family,Seema,,Wife,2026-01-15\nNew Block,N-2,staff,Old Clerk,,,\n");
        $batch = $this->spa()->post('/api/v1/residency/import', ['file' => $good], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.imported', 3)->json('data.batch_id');
        $seema = ResidencyOccupant::query()->where('name', 'Seema')->firstOrFail();
        $this->assertSame(ResidencyOccupant::query()->where('user_id', $this->staffA->id)->value('id'), $seema->family_of_id);
        $clerk = $this->spa()->getJson('/api/v1/residency/unlinked')->assertJsonCount(1, 'data')->json('data.0.id');
        $this->spa()->postJson("/api/v1/residency/occupants/{$clerk}/link", ['user_id' => $this->staffA->id])->assertStatus(422);
        $this->spa()->postJson("/api/v1/residency/occupants/{$clerk}/link", ['user_id' => $this->staffB->id])->assertOk();
        $this->spa()->getJson('/api/v1/residency/import/history')->assertJsonPath('data.0.rooms_created', 2)->assertJsonPath('data.0.buildings_created', 1);

        $this->spa()->postJson("/api/v1/residency/import/{$batch}/rollback")->assertOk()->assertJsonPath('data.0.status', 'rolled_back');
        $this->assertSame(0, ResidencyOccupant::query()->count());
        $this->assertFalse(Building::query()->where('name', 'New Block')->exists());
        $this->spa()->postJson("/api/v1/residency/import/{$batch}/rollback")->assertStatus(422);
    }
}
