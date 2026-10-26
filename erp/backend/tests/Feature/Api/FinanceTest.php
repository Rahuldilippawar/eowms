<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\BudgetTransaction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Institution;
use App\Models\MaterialPurchase;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $physics;

    private Department $library;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $college = Institution::factory()->for($this->org)->create();
        $this->physics = Department::factory()->for($this->org)->create(['institution_id' => $college->id, 'code' => 'PHY']);
        $this->library = Department::factory()->for($this->org)->create(['code' => 'LIB']);
        $this->admin = $this->userWithRole($this->org, ['finance.view', 'finance.manage', 'finance.budget', 'finance.export']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 11:00', 'Asia/Kolkata'));
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-10 11:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buy(array $override = [], ?User $as = null): TestResponse
    {
        $this->actingAs($as ?? $this->admin);

        return $this->spa()->postJson('/api/v1/finance/purchases', [
            'department_id' => $this->physics->id, 'material_name' => 'Glass beakers', 'vendor_name' => 'Lab Supplies', 'quantity' => 10, 'unit' => 'pcs',
            'rate' => 150, 'transport_cost' => 100, 'gst_percent' => 18, 'purchase_date' => '2026-06-05', ...$override,
        ]);
    }

    public function test_purchase_totals_charge_the_budget_once_and_track_payments(): void
    {
        $this->actingAs($this->admin);
        $this->spa()->putJson('/api/v1/finance/budgets', ['department_id' => $this->physics->id, 'fy' => 2026, 'allocated_amount' => 10000])->assertOk();

        // 10 × 150 = 1500, GST 18% = 270, transport 100 → 1870.
        $id = $this->buy()->assertCreated()->assertJsonPath('data.base_amount', 1500)->assertJsonPath('data.gst_amount', 270)
            ->assertJsonPath('data.total_amount', 1870)->assertJsonPath('data.payment_status', 'pending')->json('data.id');
        $this->buy(['purchase_date' => '2026-06-20'])->assertStatus(422)->assertJsonValidationErrors('purchase_date');
        $this->spa()->getJson('/api/v1/finance/budget-check?department_id='.$this->physics->id.'&amount=9000')->assertOk()
            ->assertJsonPath('data.remaining', 8130)->assertJsonPath('data.exceeds', true);

        $this->spa()->postJson("/api/v1/finance/purchases/{$id}/payments", ['amount' => 2000, 'payment_date' => '2026-06-06', 'payment_type' => 'final'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->spa()->postJson("/api/v1/finance/purchases/{$id}/payments", ['amount' => 1000, 'payment_date' => '2026-06-06', 'payment_type' => 'advance'])
            ->assertOk()->assertJsonPath('data.payment_status', 'partial')->assertJsonPath('data.due_amount', 870);
        // Changing the purchase updates its one ledger row; it cannot drop below what was paid.
        $this->spa()->putJson("/api/v1/finance/purchases/{$id}", ['department_id' => $this->physics->id, 'material_name' => 'Glass beakers', 'vendor_name' => 'Lab Supplies',
            'quantity' => 1, 'rate' => 100, 'purchase_date' => '2026-06-05'])->assertStatus(422);
        $this->spa()->putJson("/api/v1/finance/purchases/{$id}", ['department_id' => $this->physics->id, 'material_name' => 'Glass beakers', 'vendor_name' => 'Lab Supplies',
            'quantity' => 12, 'rate' => 150, 'gst_percent' => 18, 'transport_cost' => 100, 'purchase_date' => '2026-06-05'])->assertOk()->assertJsonPath('data.total_amount', 2224);
        $this->assertSame(1, BudgetTransaction::query()->count());
        $this->spa()->postJson("/api/v1/finance/purchases/{$id}/payments", ['amount' => 1224, 'payment_date' => '2026-06-07', 'payment_type' => 'final'])
            ->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->spa()->deleteJson("/api/v1/finance/purchases/{$id}")->assertStatus(422);

        $row = collect($this->spa()->getJson('/api/v1/finance/budgets?fy=2026')->assertOk()->json('data'))->firstWhere('department.id', $this->physics->id);
        $this->assertSame([10000, 2224, 7776, 22, 2224, 2224], [(int) $row['allocated'], (int) $row['spent'], (int) $row['remaining'], $row['percent_used'], (int) $row['purchases_billed'], (int) $row['purchases_paid']]);
        $this->spa()->getJson('/api/v1/finance/purchases?status=paid')->assertJsonCount(1, 'data');
        $this->spa()->getJson('/api/v1/finance/purchases?status=pending')->assertJsonCount(0, 'data');
        $this->assertStringContainsString('Glass beakers', $this->spa()->get('/api/v1/finance/transactions?fy=2026&format=csv')->streamedContent());

        // A purchase in March belongs to the year that started the April before.
        $march = $this->buy(['purchase_date' => '2026-03-31'])->json('data.id');
        $this->assertSame(2025, BudgetTransaction::query()->where('source_id', $march)->value('fy_start_year'));
        $this->spa()->deleteJson("/api/v1/finance/purchases/{$march}")->assertOk();
        $this->assertFalse(BudgetTransaction::query()->where('source_id', $march)->exists());
    }

    public function test_department_scope_limits_what_is_seen_and_managed(): void
    {
        $hod = $this->userWithRole($this->org, ['finance.view', 'finance.manage'], PermissionScope::Departments, departmentIds: [$this->physics->id]);
        $this->buy(['department_id' => $this->library->id])->assertCreated();
        $this->buy([], $hod)->assertCreated();
        $this->buy(['department_id' => $this->library->id], $hod)->assertStatus(422)->assertJsonValidationErrors('department_id');

        $this->actingAs($hod);
        $this->spa()->getJson('/api/v1/finance/purchases')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([$this->physics->id], collect($this->spa()->getJson('/api/v1/finance/budgets')->json('data'))->pluck('department.id')->all());
        $this->spa()->putJson('/api/v1/finance/budgets', ['department_id' => $this->physics->id, 'fy' => 2026, 'allocated_amount' => 5])->assertForbidden();
        $this->spa()->get('/api/v1/finance/transactions?format=csv')->assertForbidden();
        $this->spa()->getJson('/api/v1/finance/budget-check?department_id='.$this->library->id)->assertNotFound();
        $libraryPurchase = MaterialPurchase::query()->where('department_id', $this->library->id)->value('id');
        $this->spa()->getJson("/api/v1/finance/purchases/{$libraryPurchase}")->assertNotFound();

        $staff = $this->userWithRole($this->org, []);
        $this->actingAs($staff);
        $this->spa()->getJson('/api/v1/finance/purchases')->assertForbidden();
    }

    public function test_an_approved_budget_letter_is_charged_to_the_department(): void
    {
        $clerk = $this->userWithRole($this->org, []);
        Employee::factory()->in($this->physics)->create(['user_id' => $clerk->id]);
        $head = $this->userWithRole($this->org, []);
        Employee::factory()->in($this->library)->create(['user_id' => $head->id]);

        $this->actingAs($clerk);
        $id = $this->spa()->postJson('/api/v1/letters', ['subject' => 'Microscopes', 'priority' => 'normal', 'is_budget_expense' => true, 'budget_category' => 'purchase', 'requested_amount' => 5000])->json('data.id');
        $this->spa()->postJson("/api/v1/letters/{$id}/submit", ['to_user_id' => $head->id])->assertOk();
        $this->actingAs($head);
        $this->spa()->postJson("/api/v1/letters/{$id}/sign", ['password' => 'password', 'approved_amount' => 4500])->assertOk();

        $row = BudgetTransaction::query()->where('source_type', 'letter')->where('source_id', $id)->firstOrFail();
        $this->assertSame([$this->physics->id, '4500.00', 'purchase', 2026], [$row->department_id, $row->amount, $row->category, $row->fy_start_year]);
    }
}
