<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\BudgetTransaction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\RenewalItem;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Vehicle;
use App\Services\VehicleImport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class VehicleTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $transport;

    private Department $physics;

    private User $admin;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->transport = Department::factory()->for($this->org)->create(['code' => 'TRP']);
        $this->physics = Department::factory()->for($this->org)->create(['code' => 'PHY']);
        $this->admin = $this->userWithRole($this->org, ['vehicles.view', 'vehicles.log', 'vehicles.manage', 'vehicles.export', 'renewals.view']);
        $this->driver = $this->userWithRole($this->org, []);
        Employee::factory()->in($this->transport)->create(['user_id' => $this->driver->id, 'employee_code' => 'EMP-50']);
        $this->at('2026-06-10');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $date): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse("{$date} 10:00", 'Asia/Kolkata'));
        Carbon::setTestNow(CarbonImmutable::parse("{$date} 10:00", 'Asia/Kolkata'));
    }

    private function vehicle(array $override = []): TestResponse
    {
        $this->actingAs($this->admin);

        return $this->spa()->postJson('/api/v1/vehicles', [
            'department_id' => $this->transport->id, 'vehicle_number' => 'mh12 ab 1234', 'vehicle_type' => 'Bus', 'fuel_type' => 'diesel',
            'current_odometer' => 1000, 'driver_user_id' => $this->driver->id,
            'documents' => ['insurance' => '2026-06-30', 'puc' => '2027-01-15'], ...$override,
        ]);
    }

    public function test_documents_are_renewal_items_and_follow_the_vehicle(): void
    {
        $id = $this->vehicle()->assertCreated()->assertJsonPath('data.vehicle_number', 'MH12AB1234')
            ->assertJsonPath('data.documents_status', 'due_soon')->assertJsonPath('data.next_document.field', 'insurance')->json('data.id');
        $this->vehicle()->assertStatus(422)->assertJsonValidationErrors('vehicle_number');
        $items = RenewalItem::query()->where('vehicle_id', $id)->orderBy('renewal_field')->get();
        $this->assertSame(['insurance', 'puc'], $items->pluck('renewal_field')->all());
        $this->assertSame(['RTO/Transport', $this->transport->id], [$items[0]->regulatory_body, $items[0]->department_id]);

        // Renewing on the renewals page shows on the vehicle; clearing a date removes the item; moving the vehicle moves them.
        $this->actingAs($this->userWithRole($this->org, ['renewals.view', 'renewals.manage']));
        $this->spa()->postJson("/api/v1/renewals/{$items[0]->id}/renew", ['new_expiry_date' => '2027-06-30'])->assertOk();
        $this->actingAs($this->admin);
        $this->spa()->getJson("/api/v1/vehicles/{$id}")->assertJsonPath('data.documents.2.field', 'insurance')->assertJsonPath('data.documents.2.expiry_date', '2027-06-30');
        $this->spa()->putJson("/api/v1/vehicles/{$id}", [
            'department_id' => $this->physics->id, 'vehicle_number' => 'MH12AB1234', 'fuel_type' => 'diesel', 'documents' => ['puc' => null],
        ])->assertOk();
        $this->assertSame([$this->physics->id], RenewalItem::query()->where('vehicle_id', $id)->pluck('department_id')->all());

        $this->spa()->postJson("/api/v1/vehicles/{$id}/active", ['is_active' => false])->assertOk();
        $this->assertFalse(RenewalItem::query()->where('vehicle_id', $id)->value('is_active'));
        $this->assertStringContainsString('MH12AB1234', $this->spa()->get('/api/v1/vehicles?format=csv&status=all')->streamedContent());
    }

    public function test_driver_logs_trips_and_fuel_which_move_the_odometer_and_budget(): void
    {
        $id = $this->vehicle()->json('data.id');
        $this->actingAs($this->driver);
        $this->spa()->getJson('/api/v1/vehicles')->assertJsonCount(1, 'data');
        $this->spa()->postJson("/api/v1/vehicles/{$id}/trips", [
            'trip_date' => '2026-06-10', 'from_location' => 'College', 'to_location' => 'Station', 'start_odometer' => 1000, 'end_odometer' => 990,
        ])->assertStatus(422)->assertJsonValidationErrors('end_odometer');
        $this->spa()->postJson("/api/v1/vehicles/{$id}/trips", [
            'trip_date' => '2026-06-10', 'from_location' => 'College', 'to_location' => 'Station', 'start_odometer' => 1000, 'end_odometer' => 1042.5,
        ])->assertCreated()->assertJsonPath('data.current_odometer', 1042.5)->assertJsonPath('data.trips.0.distance_km', 42.5);
        $this->spa()->postJson("/api/v1/vehicles/{$id}/fuel", ['fill_date' => '2026-06-10', 'odometer_reading' => 1042.5, 'liters' => 8.5, 'cost' => 800])
            ->assertCreated()->assertJsonPath('data.stats.km_per_liter', 5);
        $fuel = BudgetTransaction::query()->where('source_type', 'vehicle_fuel')->firstOrFail();
        $this->assertSame(['800.00', 'fuel', $this->transport->id], [$fuel->amount, $fuel->category, $fuel->department_id]);
        // Only managers edit directly; the vehicle manager page is closed to the driver.
        $trip = $this->spa()->getJson("/api/v1/vehicles/{$id}")->json('data.trips.0.id');
        $this->spa()->deleteJson("/api/v1/vehicles/{$id}/entries/trip/{$trip}")->assertForbidden();

        // Someone without access sees nothing.
        $this->actingAs($this->userWithRole($this->org, ['vehicles.view'], PermissionScope::Departments, departmentIds: [$this->physics->id]));
        $this->spa()->getJson("/api/v1/vehicles/{$id}")->assertNotFound();
        $this->spa()->postJson("/api/v1/vehicles/{$id}/fuel", ['fill_date' => '2026-06-10', 'odometer_reading' => 1050, 'liters' => 5])->assertNotFound();
    }

    public function test_correction_requests_are_approved_with_changes_or_rejected(): void
    {
        $id = $this->vehicle()->json('data.id');
        $this->actingAs($this->driver);
        $this->spa()->postJson("/api/v1/vehicles/{$id}/fuel", ['fill_date' => '2026-06-09', 'odometer_reading' => 1100, 'liters' => 10, 'cost' => 950])->assertCreated();
        $fuel = $this->spa()->getJson("/api/v1/vehicles/{$id}")->json('data.fuel.0.id');
        $this->spa()->postJson("/api/v1/vehicles/{$id}/entries/fuel/{$fuel}/corrections", ['request_type' => 'edit', 'reason' => 'Wrong cost typed'])->assertCreated();
        $this->spa()->postJson("/api/v1/vehicles/{$id}/entries/fuel/{$fuel}/corrections", ['request_type' => 'delete', 'reason' => 'Again please'])->assertStatus(422);
        $this->spa()->getJson('/api/v1/vehicle-corrections')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_review', false);

        $this->actingAs($this->admin);
        $request = $this->spa()->getJson('/api/v1/vehicle-corrections')->assertJsonPath('data.0.entry.cost', '950.00')->json('data.0.id');
        $this->spa()->postJson("/api/v1/vehicle-corrections/{$request}/review", ['decision' => 'approve', 'changes' => ['cost' => 590]])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('590.00', BudgetTransaction::query()->where('source_type', 'vehicle_fuel')->value('amount'));
        $this->spa()->postJson("/api/v1/vehicle-corrections/{$request}/review", ['decision' => 'reject'])->assertStatus(422);
        $this->assertSame(['vehicle.correction_approved'], UserNotification::withoutGlobalScopes()->where('user_id', $this->driver->id)->pluck('type')->all());

        // A delete request, approved, removes the entry and its budget charge.
        $this->actingAs($this->driver);
        $this->spa()->postJson("/api/v1/vehicles/{$id}/entries/fuel/{$fuel}/corrections", ['request_type' => 'delete', 'reason' => 'Duplicate fill'])->assertCreated();
        $this->actingAs($this->admin);
        $request = $this->spa()->getJson('/api/v1/vehicle-corrections')->json('data.0.id');
        $this->spa()->postJson("/api/v1/vehicle-corrections/{$request}/review", ['decision' => 'approve'])->assertOk();
        $this->assertSame(0, BudgetTransaction::query()->where('source_type', 'vehicle_fuel')->count());
        $this->spa()->getJson("/api/v1/vehicles/{$id}")->assertJsonCount(0, 'data.fuel');
    }

    public function test_service_due_reminders_by_date_and_odometer(): void
    {
        $id = $this->vehicle()->json('data.id');
        $head = $this->userWithRole($this->org, []);
        $this->transport->forceFill(['head_user_id' => $head->id])->save();
        $this->spa()->postJson("/api/v1/vehicles/{$id}/services", [
            'service_date' => '2026-06-01', 'type' => 'General service', 'cost' => 4500, 'odometer_reading' => 1000, 'next_due_date' => '2026-06-17', 'next_due_km' => 6000,
        ])->assertCreated()->assertJsonPath('data.next_service.days_left', 7)->assertJsonPath('data.next_service.due', true);
        $this->assertSame('maintenance', BudgetTransaction::query()->where('source_type', 'vehicle_service')->value('category'));

        $types = fn (User $u) => UserNotification::withoutGlobalScopes()->where('user_id', $u->id)->pluck('type')->all();
        $this->artisan('erp:vehicle-service-due')->assertSuccessful();
        $this->artisan('erp:vehicle-service-due')->assertSuccessful();
        $this->assertSame(['vehicle.service_due'], $types($this->driver));
        $this->assertSame(['vehicle.service_due'], $types($head));

        // The odometer crossing into the last 500 km sends its own reminder once.
        $this->actingAs($this->driver);
        $this->spa()->postJson("/api/v1/vehicles/{$id}/trips", ['trip_date' => '2026-06-10', 'from_location' => 'A', 'to_location' => 'B', 'start_odometer' => 1000, 'end_odometer' => 5600])->assertCreated();
        $this->artisan('erp:vehicle-service-due')->assertSuccessful();
        $this->assertSame(['vehicle.service_due', 'vehicle.service_km'], $types($head));
        $this->at('2026-06-18');
        $this->artisan('erp:vehicle-service-due')->assertSuccessful();
        $this->assertContains('vehicle.service_overdue', $types($head));
    }

    public function test_report_and_import(): void
    {
        $id = $this->vehicle()->json('data.id');
        $this->spa()->postJson("/api/v1/vehicles/{$id}/trips", ['trip_date' => '2026-05-02', 'from_location' => 'A', 'to_location' => 'B', 'start_odometer' => 1000, 'end_odometer' => 1100]);
        $this->spa()->getJson("/api/v1/vehicles/{$id}/report?from=2026-05-01&to=2026-05-31")->assertOk()->assertJsonPath('data.totals.km', 100)->assertJsonPath('data.totals.trips', 1);
        $this->assertStringContainsString('A → B', $this->spa()->get("/api/v1/vehicles/{$id}/report?from=2026-05-01&to=2026-05-31&format=csv")->streamedContent());

        $header = implode(',', VehicleImport::COLUMNS);
        $bad = UploadedFile::fake()->createWithContent('v.csv', "{$header}\nMH12AB1234,TRP,,,,,,,,,,,,,,,,,\nMH14XY1,NOPE,,,,,,,,rocket,,EMP-00,,,31/02/2027,,,,\n");
        $rows = collect($this->spa()->post('/api/v1/vehicles/import/preview', ['file' => $bad], ['Accept' => 'application/json'])->assertOk()->json('data.rows'));
        $this->assertArrayHasKey('vehicle_number', $rows[0]['errors']);
        $this->assertEqualsCanonicalizing(['department_code', 'fuel_type', 'driver_employee_code', 'insurance_validity'], array_keys($rows[1]['errors']));
        $good = UploadedFile::fake()->createWithContent('v.csv', "{$header}\nMH14 XY 0001,TRP,Van,Eeco,,,,,7,petrol,500,emp-50,,,2027-03-31,,,,\n");
        $this->spa()->post('/api/v1/vehicles/import', ['file' => $good], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.imported', 1);
        $van = Vehicle::query()->where('vehicle_number', 'MH14XY0001')->firstOrFail();
        $this->assertSame([$this->driver->id, '2027-03-31'], [$van->driver_user_id, RenewalItem::query()->where('vehicle_id', $van->id)->value('expiry_date')->toDateString()]);
    }
}
