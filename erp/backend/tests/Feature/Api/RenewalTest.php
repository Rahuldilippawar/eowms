<?php

namespace Tests\Feature\Api;

use App\Console\Commands\RenewalReminders;
use App\Enums\PermissionScope;
use App\Models\BudgetTransaction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\RenewalItem;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class RenewalTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $it;

    private Department $physics;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        Storage::fake('local');
        $this->org = Organization::factory()->create();
        $this->it = Department::factory()->for($this->org)->create(['code' => 'IT']);
        $this->physics = Department::factory()->for($this->org)->create(['code' => 'PHY']);
        $this->admin = $this->userWithRole($this->org, ['renewals.view', 'renewals.manage', 'renewals.export', 'finance.view']);
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

    private function item(array $override = [], ?User $as = null): TestResponse
    {
        $this->actingAs($as ?? $this->admin);

        return $this->spa()->postJson('/api/v1/renewals', [
            'department_id' => $this->it->id, 'item_type' => 'Software License', 'item_name' => 'Antivirus', 'expiry_date' => '2026-06-30',
            'renewal_cost' => 12000, 'vendor_name' => 'Quick Heal', ...$override,
        ]);
    }

    public function test_status_renew_history_and_payments_charged_to_the_budget(): void
    {
        $id = $this->item()->assertCreated()->assertJsonPath('data.days_left', 20)->assertJsonPath('data.status', 'due_soon')->json('data.id');
        $this->item(['item_name' => 'Fire NOC', 'expiry_date' => '2026-05-01', 'regulatory_body' => 'Other Government'])->assertJsonPath('data.status', 'expired');
        $this->item(['item_name' => 'Insurance', 'expiry_date' => '2027-01-01'])->assertJsonPath('data.status', 'active');
        $this->item(['regulatory_body' => 'Nope'])->assertStatus(422)->assertJsonValidationErrors('regulatory_body');

        $this->spa()->getJson('/api/v1/renewals')->assertOk()->assertJsonPath('meta.summary', ['expired' => 1, 'due_soon' => 1, 'active' => 1])
            ->assertJsonPath('data.0.item_name', 'Fire NOC')->assertJsonPath('meta.by_regulatory_body.0.expired', 1);
        $this->spa()->getJson('/api/v1/renewals?status=due_soon')->assertJsonCount(1, 'data');

        // Pay part of this cycle; it is charged to the IT budget.
        $this->spa()->postJson("/api/v1/renewals/{$id}/payments", ['amount' => 5000, 'payment_date' => '2026-06-11', 'payment_type' => 'advance'])->assertStatus(422);
        $this->spa()->postJson("/api/v1/renewals/{$id}/payments", ['amount' => 5000, 'payment_date' => '2026-06-09', 'payment_type' => 'advance', 'payment_mode' => 'UPI'])
            ->assertOk()->assertJsonPath('data.cycle_paid', 5000);
        $charge = BudgetTransaction::query()->where('source_type', 'renewal_payment')->firstOrFail();
        $this->assertSame(['5000.00', 'renewal', $this->it->id], [$charge->amount, $charge->category, $charge->department_id]);

        // Renewing moves the old cycle into the history; the new cycle starts unpaid.
        $this->spa()->postJson("/api/v1/renewals/{$id}/renew", ['new_expiry_date' => '2026-06-01'])->assertStatus(422)->assertJsonValidationErrors('new_expiry_date');
        $renewed = $this->spa()->postJson("/api/v1/renewals/{$id}/renew", ['new_expiry_date' => '2027-06-30', 'renewal_cost' => 13000])->assertOk()
            ->assertJsonPath('data.expiry_date', '2027-06-30')->assertJsonPath('data.status', 'active')->assertJsonPath('data.cycle_paid', 0)
            ->assertJsonPath('data.history.0.old_expiry_date', '2026-06-30')->assertJsonPath('data.history.0.old_renewal_cost', 12000);
        $this->assertCount(1, $renewed->json('data.payments'));

        $costs = $this->spa()->getJson('/api/v1/renewals/costs')->assertOk()->json('data');
        // The insurance item (also 2027, same type) adds its 12,000.
        $this->assertSame([2027, 'Software License', 25000], [$costs['by_year_type'][0]['year'], $costs['by_year_type'][0]['item_type'], (int) $costs['by_year_type'][0]['total']]);
        $this->assertSame(5000, (int) $costs['paid_by_year'][0]['total']);
        $this->assertStringContainsString('Antivirus', $this->spa()->get('/api/v1/renewals?format=csv')->streamedContent());
        $this->spa()->getJson('/api/v1/renewals/calendar?month=2026-05')->assertJsonCount(1, 'data')->assertJsonPath('data.0.item_name', 'Fire NOC');
    }

    public function test_monthly_bills_list_the_months_still_to_pay(): void
    {
        $id = $this->item(['item_name' => 'Building rent', 'item_type' => 'Rent', 'expiry_date' => '2027-03-31', 'is_recurring_monthly' => true, 'monthly_amount' => 8000, 'recurring_start_month' => '2026-04'])
            ->assertCreated()->json('data.id');
        $this->item(['is_recurring_monthly' => true])->assertStatus(422)->assertJsonValidationErrors(['monthly_amount', 'recurring_start_month']);
        $this->spa()->postJson("/api/v1/renewals/{$id}/payments", ['amount' => 8000, 'payment_date' => '2026-05-02', 'payment_type' => 'final'])->assertStatus(422)->assertJsonValidationErrors('for_month');
        $this->spa()->postJson("/api/v1/renewals/{$id}/payments", ['amount' => 8000, 'payment_date' => '2026-05-02', 'payment_type' => 'final', 'for_month' => '2026-04'])->assertOk();
        $this->spa()->getJson("/api/v1/renewals/{$id}")->assertJsonPath('data.months', [
            ['month' => '2026-04', 'paid' => true], ['month' => '2026-05', 'paid' => false], ['month' => '2026-06', 'paid' => false],
        ]);
    }

    public function test_scope_attachments_import_and_deactivate(): void
    {
        $hod = $this->userWithRole($this->org, ['renewals.view', 'renewals.manage'], PermissionScope::Departments, departmentIds: [$this->physics->id]);
        $id = $this->item()->json('data.id');
        $this->item([], $hod)->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->actingAs($hod);
        $this->spa()->getJson("/api/v1/renewals/{$id}")->assertNotFound();
        $this->spa()->getJson('/api/v1/renewals')->assertJsonCount(0, 'data');
        $this->spa()->get('/api/v1/renewals?format=csv')->assertForbidden();

        $this->actingAs($this->admin);
        $file = UploadedFile::fake()->createWithContent('certificate.pdf', "%PDF-1.4\n%%EOF");
        $attachment = $this->spa()->post("/api/v1/renewals/{$id}/attachments", ['file' => $file, 'title' => '2026 certificate'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.attachments.0.cycle_expiry_date', '2026-06-30')->json('data.attachments.0.id');
        $this->spa()->get("/api/v1/renewals/{$id}/attachments/{$attachment}")->assertOk();
        $this->actingAs($hod);
        $this->spa()->get("/api/v1/renewals/{$id}/attachments/{$attachment}")->assertNotFound();

        $this->actingAs($this->admin);
        $owner = $this->userWithRole($this->org, []);
        Employee::factory()->in($this->physics)->create(['user_id' => $owner->id, 'employee_code' => 'EMP-77']);
        $header = 'department_code,item_type,regulatory_body,item_name,reference_no,issued_date,expiry_date,renewal_cost,vendor_name,vendor_contact,responsible_employee_code';
        $bad = UploadedFile::fake()->createWithContent('r.csv', "{$header}\nNOPE,AMC,,Lift AMC,,,2027-01-01,,,,\nPHY,Approval,AICTE,AICTE,,,31/02/2027,,,,\nPHY,Approval,Bogus,X,,,2027-01-01,,,,EMP-00\n");
        $rows = collect($this->spa()->post('/api/v1/renewals/import/preview', ['file' => $bad], ['Accept' => 'application/json'])->assertOk()->json('data.rows'));
        $this->assertArrayHasKey('department_code', $rows[0]['errors']);
        $this->assertArrayHasKey('expiry_date', $rows[1]['errors']);
        $this->assertEqualsCanonicalizing(['regulatory_body', 'responsible_employee_code'], array_keys($rows[2]['errors']));
        $good = UploadedFile::fake()->createWithContent('r.csv', "{$header}\nPHY,Approval,AICTE,AICTE approval,A-1,01/06/2026,31/05/2027,25000,,,emp-77\n");
        $this->spa()->post('/api/v1/renewals/import', ['file' => $good], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.imported', 1);
        $this->assertSame($owner->id, RenewalItem::query()->where('item_name', 'AICTE approval')->value('responsible_user_id'));

        $this->spa()->postJson("/api/v1/renewals/{$id}/active", ['is_active' => false])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->spa()->getJson('/api/v1/renewals?status=inactive')->assertJsonCount(1, 'data');
    }

    public function test_reminder_milestones_and_recipients(): void
    {
        $this->assertSame(['d30', 'd30', 'd30', null, 'd0', 'o1', 'o1', null, 'o8', null], array_map(fn ($d) => RenewalReminders::milestone($d), [30, 29, 28, 27, 0, -1, -3, -4, -8, -12]));

        $owner = $this->userWithRole($this->org, []);
        $head = $this->userWithRole($this->org, []);
        $this->it->forceFill(['head_user_id' => $head->id])->save();
        $this->item(['responsible_user_id' => null, 'expiry_date' => '2026-07-10']);
        Employee::factory()->in($this->it)->create(['user_id' => $owner->id]);
        $this->item(['item_name' => 'Owned', 'responsible_user_id' => $owner->id, 'expiry_date' => '2026-07-10']);

        // 30 days left: the owner of "Owned", and the head for the item nobody owns.
        $this->artisan('erp:renewal-reminders')->assertSuccessful();
        $this->artisan('erp:renewal-reminders')->assertSuccessful();
        $types = fn (User $u) => UserNotification::withoutGlobalScopes()->where('user_id', $u->id)->pluck('type')->all();
        $this->assertSame(['renewal.due'], $types($owner));
        $this->assertSame(['renewal.due'], $types($head));

        // 7 days left: the head hears about the owned item too.
        $this->at('2026-07-03');
        $this->artisan('erp:renewal-reminders')->assertSuccessful();
        $this->assertSame(['renewal.due', 'renewal.due', 'renewal.due'], $types($head));
    }
}
