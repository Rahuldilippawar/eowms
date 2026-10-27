<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\BudgetTransaction;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class StoreAndPurchasingTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private User $keeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->for($this->org)->create();
        $this->keeper = $this->userWithRole($this->org, ['store.view', 'store.manage']);
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-10 11:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(string $name = 'Cement', float $reorder = 5): int
    {
        $this->actingAs($this->keeper);

        return $this->spa()->postJson('/api/v1/store/items', ['item_name' => $name, 'unit' => 'bag', 'category' => 'Construction', 'reorder_level' => $reorder])
            ->assertCreated()->json('data.id');
    }

    public function test_stock_moves_in_and_out_never_below_zero_and_warns_at_reorder_level(): void
    {
        $other = $this->userWithRole($this->org, ['store.view', 'store.manage']);
        $id = $this->item();
        $this->spa()->postJson('/api/v1/store/items', ['item_name' => 'Cement', 'unit' => 'bag'])->assertStatus(422)->assertJsonValidationErrors('item_name');
        $this->spa()->postJson('/api/v1/store/items', ['item_name' => 'Sand', 'unit' => 'brass', 'reorder_level' => null])->assertCreated()->assertJsonPath('data.reorder_level', 0);

        $move = fn (array $d) => $this->spa()->postJson("/api/v1/store/items/{$id}/movements", $d);
        $move(['movement_type' => 'receive', 'quantity' => 10, 'unit_cost' => 400])->assertOk()->assertJsonPath('data.balance', 10);
        $move(['movement_type' => 'issue', 'quantity' => 12, 'department_id' => $this->dept->id])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $move(['movement_type' => 'issue', 'quantity' => 6, 'department_id' => $this->dept->id, 'issued_to' => 'Site supervisor'])->assertOk()->assertJsonPath('data.low', true);
        $move(['movement_type' => 'issue', 'quantity' => 1])->assertOk();
        $move(['movement_type' => 'adjust', 'quantity' => -1])->assertStatus(422)->assertJsonValidationErrors('remarks');
        $move(['movement_type' => 'adjust', 'quantity' => -1, 'remarks' => 'One bag damaged'])->assertOk()
            ->assertJsonPath('data.balance', 2)->assertJsonPath('data.movements.0.balance', 2)->assertJsonPath('data.movements.3.balance', 10);
        $move(['movement_type' => 'issue', 'quantity' => 1, 'movement_date' => '2026-06-11'])->assertStatus(422)->assertJsonValidationErrors('movement_date');

        // One reminder to the other store managers when the stock reached the reorder level, not on every later issue.
        $this->assertSame(['store.low_stock'], UserNotification::withoutGlobalScopes()->where('user_id', $other->id)->pluck('type')->all());
        $this->spa()->getJson('/api/v1/store/items?low=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.low_count', 1);
        $issues = collect($this->spa()->getJson('/api/v1/store/issues?from=2026-06-01&to=2026-06-30')->json('data'))->pluck('quantity', 'department')->all();
        $this->assertEquals(['' => 1, $this->dept->name => 6], $issues);
        $this->assertStringContainsString('Cement', $this->spa()->get('/api/v1/store/items?format=csv')->streamedContent());

        $this->actingAs($this->userWithRole($this->org, ['store.view']));
        $this->spa()->getJson("/api/v1/store/items/{$id}")->assertOk();
        $move(['movement_type' => 'receive', 'quantity' => 1])->assertForbidden();
    }

    public function test_quotation_to_order_goods_invoice_and_payment(): void
    {
        $paper = $this->item('A4 paper', 0);
        DepartmentBudget::create(['organization_id' => $this->org->id, 'department_id' => $this->dept->id, 'fy_start_year' => 2026, 'allocated_amount' => 50000]);
        $buyer = $this->userWithRole($this->org, ['purchasing.view', 'purchasing.manage'], PermissionScope::Departments, departmentIds: [$this->dept->id]);
        $this->actingAs($buyer);

        $vendor = $this->spa()->postJson('/api/v1/purchasing/vendors', ['name' => 'Shree Traders', 'gstin' => '27abcde1234f1z5'])->assertCreated()
            ->assertJsonPath('data.gstin', '27ABCDE1234F1Z5')->json('data.id');
        $other = Department::factory()->for($this->org)->create();
        $lines = [['item_id' => $paper, 'quantity' => 10, 'rate' => 250, 'gst_percent' => 12], ['description' => 'Delivery to college', 'quantity' => 1, 'rate' => 200]];
        $this->spa()->postJson('/api/v1/purchasing/quotations', ['department_id' => $other->id, 'vendor_id' => $vendor, 'quotation_date' => '2026-06-01', 'lines' => $lines])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
        $q = $this->spa()->postJson('/api/v1/purchasing/quotations', ['department_id' => $this->dept->id, 'vendor_id' => $vendor, 'quotation_date' => '2026-06-01', 'reference_no' => 'Q-77', 'lines' => $lines])
            ->assertCreated()->assertJsonPath('data.total_amount', 3000)->assertJsonPath('data.lines.0.description', 'A4 paper')->assertJsonPath('data.lines.0.unit', 'bag')->json('data.id');

        // Accepting makes a draft order; the draft can change until it is issued.
        $order = $this->spa()->postJson("/api/v1/purchasing/quotations/{$q}/decide", ['decision' => 'accept'])->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.po_number', null)->assertJsonPath('data.budget_left', 50000)->json('data');
        $this->spa()->postJson("/api/v1/purchasing/quotations/{$q}/decide", ['decision' => 'reject'])->assertStatus(422);
        $lines[0]['quantity'] = 20;
        $this->spa()->putJson("/api/v1/purchasing/orders/{$order['id']}", ['department_id' => $this->dept->id, 'vendor_id' => $vendor, 'order_date' => '2026-06-02', 'lines' => $lines])
            ->assertOk()->assertJsonPath('data.subtotal', 5200)->assertJsonPath('data.gst_amount', 600)->assertJsonPath('data.total_amount', 5800);
        $this->spa()->postJson("/api/v1/purchasing/orders/{$order['id']}/issue")->assertOk()->assertJsonPath('data.po_number', 'PO/26-27/001')->assertJsonPath('data.status', 'issued');
        $this->spa()->putJson("/api/v1/purchasing/orders/{$order['id']}", ['department_id' => $this->dept->id, 'vendor_id' => $vendor, 'order_date' => '2026-06-02', 'lines' => $lines])->assertStatus(422);
        $this->spa()->postJson("/api/v1/purchasing/orders/{$order['id']}/invoices", ['invoice_number' => 'INV-1', 'invoice_date' => '2026-06-05', 'goods_amount' => 100])->assertStatus(422);

        // The store receives part of it: stock goes up at the order rate.
        $this->actingAs($this->keeper);
        $detail = $this->spa()->getJson("/api/v1/purchasing/orders/{$order['id']}")->assertOk()->assertJsonPath('data.can_receive', true)->assertJsonPath('data.can_manage', false)->json('data');
        [$paperLine, $deliveryLine] = array_column($detail['lines'], 'id');
        $receive = fn (array $l) => $this->spa()->postJson("/api/v1/purchasing/orders/{$order['id']}/receive", ['received_date' => '2026-06-05', 'lines' => $l]);
        $receive([['line_id' => $paperLine, 'quantity' => 25]])->assertStatus(422);
        $receive([['line_id' => $paperLine, 'quantity' => 15]])->assertOk()->assertJsonPath('data.status', 'partly_received')
            ->assertJsonPath('data.receipts.0.grn_number', 'GRN/26-27/001')->assertJsonPath('data.received_value', 4200);
        $this->spa()->getJson("/api/v1/store/items/{$paper}")->assertJsonPath('data.balance', 15)->assertJsonPath('data.movements.0.unit_cost', 250)->assertJsonPath('data.movements.0.from_purchase', true);
        $this->spa()->postJson("/api/v1/purchasing/orders/{$order['id']}/invoices", ['invoice_number' => 'INV-1', 'invoice_date' => '2026-06-05', 'goods_amount' => 100])->assertForbidden();

        // The invoice may bill only what came; it is charged to the budget and paid in parts.
        $this->actingAs($buyer);
        $invoice = fn (array $d) => $this->spa()->postJson("/api/v1/purchasing/orders/{$order['id']}/invoices", $d + ['invoice_date' => '2026-06-06']);
        $invoice(['invoice_number' => 'INV-1', 'goods_amount' => 5000])->assertStatus(422)->assertJsonValidationErrors('goods_amount');
        $inv = $invoice(['invoice_number' => 'INV-1', 'goods_amount' => 4200, 'other_charges' => 150])->assertOk()
            ->assertJsonPath('data.invoices.0.total_amount', 4350)->assertJsonPath('data.invoiceable_amount', 0)->assertJsonPath('data.budget_left', 45650)->json('data.invoices.0.id');
        $invoice(['invoice_number' => 'INV-1', 'goods_amount' => 1])->assertStatus(422)->assertJsonValidationErrors('invoice_number');
        $this->assertSame(4350.0, (float) BudgetTransaction::query()->where('source_type', 'purchase_invoice')->value('amount'));
        $pay = fn (float $a) => $this->spa()->postJson("/api/v1/purchasing/invoices/{$inv}/payments", ['amount' => $a, 'payment_date' => '2026-06-08', 'payment_type' => 'partial']);
        $pay(5000)->assertStatus(422);
        $pay(4000)->assertOk()->assertJsonPath('data.invoices.0.due_amount', 350);
        $this->spa()->deleteJson("/api/v1/purchasing/invoices/{$inv}")->assertStatus(422);

        // The rest arrives; a received order can no longer be cancelled.
        $receive([['line_id' => $paperLine, 'quantity' => 5], ['line_id' => $deliveryLine, 'quantity' => 1]])->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.invoiceable_amount', 1600);
        $this->spa()->postJson("/api/v1/purchasing/orders/{$order['id']}/cancel")->assertStatus(422);
        $this->spa()->getJson('/api/v1/purchasing/vendors')->assertJsonPath('data.0.orders', 1)->assertJsonPath('data.0.ordered_amount', 5800);

        // Another department's buyer sees none of it.
        $this->actingAs($this->userWithRole($this->org, ['purchasing.view', 'purchasing.manage'], PermissionScope::Departments, departmentIds: [$other->id]));
        $this->spa()->getJson("/api/v1/purchasing/orders/{$order['id']}")->assertNotFound();
        $this->spa()->getJson('/api/v1/purchasing/orders')->assertJsonCount(0, 'data');
    }

    public function test_material_used_in_work_is_reported(): void
    {
        $cement = $this->item();
        $sand = $this->item('Sand');
        $worker = $this->userWithRole($this->org, []);
        Employee::factory()->in($this->dept)->create(['user_id' => $worker->id, 'name' => 'Ganesh']);
        $this->actingAs($worker);
        $this->spa()->getJson('/api/v1/me/work/materials')->assertJsonCount(2, 'data');
        $entry = ['work_date' => '2026-06-10', 'title' => 'Plastered the store wall', 'status' => 'completed'];
        $this->spa()->postJson('/api/v1/me/work', $entry + ['materials' => [['item_id' => $cement, 'quantity' => 2], ['item_id' => $cement, 'quantity' => 1]]])->assertStatus(422);
        $id = $this->spa()->postJson('/api/v1/me/work', $entry + ['materials' => [['item_id' => $cement, 'quantity' => 2], ['item_id' => $sand, 'quantity' => 0.5]]])
            ->assertCreated()->assertJsonCount(2, 'data.materials')->assertJsonPath('data.materials.0.unit', 'bag')->json('data.id');
        StoreItem::query()->whereKey($sand)->update(['is_active' => false]);
        // An item made inactive later stays on the entry; a new one cannot be added.
        $this->spa()->putJson("/api/v1/me/work/{$id}", $entry + ['materials' => [['item_id' => $cement, 'quantity' => 3], ['item_id' => $sand, 'quantity' => 0.5]]])->assertOk();
        $this->spa()->postJson('/api/v1/me/work', $entry + ['title' => 'More', 'materials' => [['item_id' => $sand, 'quantity' => 1]]])->assertStatus(422)->assertJsonValidationErrors('materials.0.item_id');
        $this->spa()->postJson('/api/v1/me/work', $entry + ['title' => 'Fixed the gate', 'materials' => [['item_id' => $cement, 'quantity' => 1]]])->assertCreated();

        $this->actingAs($this->userWithRole($this->org, ['daily_work.view'], PermissionScope::Departments, departmentIds: [$this->dept->id]));
        $report = $this->spa()->getJson('/api/v1/work/materials?from=2026-06-01&to=2026-06-10')->assertOk()->json('data');
        $this->assertEquals([['Cement', 4, 2], ['Sand', 0.5, 1]], array_map(fn ($t) => [$t['item_name'], $t['quantity'], $t['entries']], $report['totals']));
        $this->assertCount(3, $report['rows']);
        $this->actingAs($this->userWithRole($this->org, ['daily_work.view'], PermissionScope::Departments, departmentIds: [Department::factory()->for($this->org)->create()->id]));
        $this->spa()->getJson('/api/v1/work/materials?from=2026-06-01&to=2026-06-10')->assertJsonCount(0, 'data.totals');
    }
}
