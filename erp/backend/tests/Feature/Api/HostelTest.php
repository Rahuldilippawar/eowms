<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
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

class HostelTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private User $admin;

    private User $warden;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->for($this->org)->create(['code' => 'HOS']);
        $this->admin = $this->userWithRole($this->org, ['hostels.view', 'hostels.care', 'hostels.manage', 'hostels.export']);
        $this->warden = $this->userWithRole($this->org, []);
        Employee::factory()->in($this->dept)->create(['user_id' => $this->warden->id]);
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hostel(): int
    {
        $this->actingAs($this->admin);
        $id = $this->spa()->postJson('/api/v1/hostels', ['name' => 'Boys Hostel', 'department_id' => $this->dept->id, 'gender' => 'boys', 'capacity' => 4])->assertCreated()->json('data.id');
        $this->spa()->postJson("/api/v1/hostels/{$id}/rooms", ['room_number' => '101', 'capacity' => 1])->assertOk();
        $this->spa()->postJson("/api/v1/hostels/{$id}/rooms", ['room_number' => '102', 'capacity' => 2])->assertOk();
        $this->spa()->putJson("/api/v1/hostels/{$id}/staff", ['staff' => [['user_id' => $this->warden->id]]])->assertOk()->assertJsonPath('data.staff.0.user_id', $this->warden->id);

        return $id;
    }

    public function test_warden_looks_after_residents_beds_out_passes_health_fees_and_departure(): void
    {
        $hostel = $this->hostel();
        $rooms = collect($this->spa()->getJson("/api/v1/hostels/{$hostel}")->json('data.rooms'))->pluck('id', 'room_number');

        // The warden, with no hostel permission, works on this hostel only.
        $this->actingAs($this->warden);
        $this->spa()->getJson('/api/v1/hostels')->assertJsonCount(1, 'data');
        $this->spa()->putJson("/api/v1/hostels/{$hostel}", ['name' => 'X', 'department_id' => $this->dept->id, 'gender' => 'boys'])->assertForbidden();
        $a = $this->spa()->postJson("/api/v1/hostels/{$hostel}/residents", ['name' => 'Suraj', 'gender' => 'male', 'admission_date' => '2026-06-15'])->assertCreated()->json('data.id');
        $b = $this->spa()->postJson("/api/v1/hostels/{$hostel}/residents", ['name' => 'Rohit', 'gender' => 'male'])->assertCreated()->json('data.id');

        $this->spa()->postJson("/api/v1/hostel-residents/{$a}/allocate", ['room_id' => $rooms['101'], 'bed_label' => 'A'])->assertOk()->assertJsonPath('data.room.room_number', '101');
        $this->spa()->postJson("/api/v1/hostel-residents/{$b}/allocate", ['room_id' => $rooms['101']])->assertStatus(422)->assertJsonValidationErrors('room_id');
        $this->spa()->postJson("/api/v1/hostel-residents/{$b}/allocate", ['room_id' => $rooms['102']])->assertOk();
        // Moving frees the old bed.
        $this->spa()->postJson("/api/v1/hostel-residents/{$a}/allocate", ['room_id' => $rooms['102']])->assertOk()->assertJsonCount(2, 'data.allocations');
        $this->spa()->postJson("/api/v1/hostel-residents/{$b}/allocate", ['room_id' => $rooms['101']])->assertOk();

        $this->spa()->postJson("/api/v1/hostel-residents/{$a}/out", ['leave_date' => '2026-07-08', 'expected_return_date' => '2026-07-09', 'reason' => 'Home'])->assertOk()->assertJsonPath('data.out.overdue', true);
        $this->spa()->postJson("/api/v1/hostel-residents/{$a}/out", ['leave_date' => '2026-07-10'])->assertStatus(422);
        $this->spa()->getJson("/api/v1/hostels/{$hostel}/residents?status=out")->assertJsonCount(1, 'data');
        $this->spa()->getJson("/api/v1/hostels/{$hostel}")->assertJsonPath('data.stats.overdue_returns', 1)->assertJsonPath('data.stats.occupied_beds', 2);

        $this->spa()->putJson("/api/v1/hostel-residents/{$b}/health", ['blood_group' => 'O+', 'allergies' => 'Peanuts'])->assertOk()->assertJsonPath('data.health.blood_group', 'O+');
        $this->spa()->postJson("/api/v1/hostel-residents/{$b}/incidents", ['incident_date' => '2026-07-09', 'description' => 'Fever', 'action_taken' => 'Doctor visit'])->assertOk()->assertJsonCount(1, 'data.incidents');
        $fee = $this->spa()->postJson("/api/v1/hostel-residents/{$b}/fees", ['fee_period' => 'July 2026', 'amount_due' => 1500])->assertOk()->json('data.fees.0.id');
        $this->spa()->postJson("/api/v1/hostel-fees/{$fee}/payments", ['amount' => 2000, 'payment_mode' => 'cash'])->assertStatus(422);
        $this->spa()->postJson("/api/v1/hostel-fees/{$fee}/payments", ['amount' => 1000, 'payment_mode' => 'online'])->assertOk()
            ->assertJsonPath('data.fees.0.status', 'partial')->assertJsonPath('data.fees.0.balance', 500);
        $this->spa()->getJson("/api/v1/hostels/{$hostel}")->assertJsonPath('data.stats.fees_due', 500);

        $this->spa()->postJson("/api/v1/hostel-residents/{$a}/depart", ['departure_date' => '2026-07-10', 'departure_reason' => 'Finished 10th', 'contact_number' => '9876500000'])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.room', null)->assertJsonPath('data.out', null)->assertJsonPath('data.alumnus.departure_reason', 'Finished 10th');
        $this->spa()->getJson("/api/v1/hostels/{$hostel}/residents?status=left")->assertJsonCount(1, 'data');
        $this->spa()->putJson("/api/v1/hostel-residents/{$a}/alumnus", ['current_status' => 'Junior college'])->assertOk()->assertJsonPath('data.alumnus.current_status', 'Junior college');
        $this->spa()->postJson("/api/v1/hostel-residents/{$a}/out", ['leave_date' => '2026-07-10'])->assertStatus(422);

        // Someone outside the hostel sees nothing; CSV needs the export permission.
        $this->actingAs($this->userWithRole($this->org, ['hostels.view', 'hostels.care'], PermissionScope::Departments, departmentIds: [Department::factory()->for($this->org)->create()->id]));
        $this->spa()->getJson("/api/v1/hostel-residents/{$b}")->assertNotFound();
        $this->actingAs($this->warden);
        $this->spa()->get("/api/v1/hostels/{$hostel}/residents?format=csv")->assertForbidden();
        $this->actingAs($this->admin);
        $this->assertStringContainsString('Rohit', $this->spa()->get("/api/v1/hostels/{$hostel}/residents?format=csv")->streamedContent());
    }

    public function test_roll_calls_suggest_on_leave_and_missing_ones_are_reported(): void
    {
        $hostel = $this->hostel();
        $head = $this->userWithRole($this->org, []);
        $this->dept->forceFill(['head_user_id' => $head->id])->save();
        $this->actingAs($this->warden);
        $a = $this->spa()->postJson("/api/v1/hostels/{$hostel}/residents", ['name' => 'Suraj'])->json('data.id');
        $b = $this->spa()->postJson("/api/v1/hostels/{$hostel}/residents", ['name' => 'Rohit'])->json('data.id');
        $this->spa()->postJson("/api/v1/hostels/{$hostel}/residents", ['name' => 'Later', 'admission_date' => '2026-08-01'])->assertCreated();
        $this->spa()->postJson("/api/v1/hostel-residents/{$b}/out", ['leave_date' => '2026-07-09'])->assertOk();

        $sheet = $this->spa()->getJson("/api/v1/hostels/{$hostel}/attendance?session=morning")->assertOk()->assertJsonPath('data.marked', false)->json('data.residents');
        $this->assertSame(['Rohit' => 'on_leave', 'Suraj' => 'present'], collect($sheet)->pluck('status', 'name')->all());
        $this->spa()->getJson("/api/v1/hostels/{$hostel}/attendance?session=morning&date=2026-07-11")->assertStatus(422);
        $this->spa()->postJson("/api/v1/hostels/{$hostel}/attendance?session=morning", ['entries' => [
            ['resident_id' => $a, 'status' => 'absent', 'remark' => 'Sick bay'], ['resident_id' => $b, 'status' => 'on_leave'],
        ]])->assertOk()->assertJsonPath('data.marked', true)->assertJsonPath('data.sessions_marked', ['morning']);
        $this->spa()->getJson("/api/v1/hostels/{$hostel}")->assertJsonPath('data.stats.today.morning.absent', 1)->assertJsonPath('data.stats.today.evening', null);

        $this->artisan('erp:hostel-attendance-check')->assertSuccessful();
        $this->artisan('erp:hostel-attendance-check')->assertSuccessful();
        $types = fn (User $u) => UserNotification::withoutGlobalScopes()->where('user_id', $u->id)->pluck('type')->all();
        $this->assertSame(['hostel.attendance_missing'], $types($this->warden));
        $this->assertSame(['hostel.attendance_missing'], $types($head));
        $this->assertSame('afternoon,evening', UserNotification::withoutGlobalScopes()->where('user_id', $head->id)->first()->data['sessions']);
    }

    public function test_resident_import_checks_every_row(): void
    {
        $hostel = $this->hostel();
        $header = 'name,school_name,class_grade,district,gender,admission_date,date_of_birth,guardian_name,guardian_phone';
        $bad = UploadedFile::fake()->createWithContent('r.csv', "{$header}\n,ZP,7,Thane,male,,,,\nPriya,ZP,6,Palghar,other,31/02/2026,,,\n");
        $rows = collect($this->spa()->post("/api/v1/hostels/{$hostel}/residents/import/preview", ['file' => $bad], ['Accept' => 'application/json'])->assertOk()->json('data.rows'));
        $this->assertArrayHasKey('name', $rows[0]['errors']);
        $this->assertEqualsCanonicalizing(['gender', 'admission_date'], array_keys($rows[1]['errors']));
        $good = UploadedFile::fake()->createWithContent('r.csv', "{$header}\nसूरज कांबळे,जि.प. शाळा,७ वी,ठाणे,मुलगा,15/06/2026,2013-03-20,रमेश,9876543210\n");
        $this->spa()->post("/api/v1/hostels/{$hostel}/residents/import", ['file' => $good], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.imported', 1);
        $this->spa()->getJson("/api/v1/hostels/{$hostel}/residents")->assertJsonPath('data.0.gender', 'male')->assertJsonPath('data.0.admission_date', '2026-06-15');
    }
}
