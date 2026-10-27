<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\AssetAllocation;
use App\Models\BudgetTransaction;
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

class AssetsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->for($this->org)->create(['code' => 'phy']);
        $this->admin = $this->userWithRole($this->org, ['assets.view', 'assets.manage', 'assets.export']);
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dead_stock_register_codes_budget_charge_scope_and_report(): void
    {
        $this->actingAs($this->admin);
        $header = 'item_name,category,default_unit';
        $bad = UploadedFile::fake()->createWithContent('m.csv', "{$header}\n,Furniture,piece\nBench,Furniture,piece\nbench,Furniture,piece\n");
        $rows = collect($this->spa()->post('/api/v1/assets/masters/import/preview', ['file' => $bad], ['Accept' => 'application/json'])->assertOk()->json('data.rows'));
        $this->assertArrayHasKey('item_name', $rows[0]['errors']);
        $this->assertArrayHasKey('item_name', $rows[2]['errors']);
        $good = UploadedFile::fake()->createWithContent('m.csv', "{$header}\nBench,Furniture,piece\nMicroscope,Lab,piece\n");
        $this->spa()->post('/api/v1/assets/masters/import', ['file' => $good], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.imported', 2);
        $masters = collect($this->spa()->getJson('/api/v1/assets/masters')->json('data'))->pluck('id', 'item_name');

        $new = fn (array $d) => $this->spa()->postJson('/api/v1/assets/dead-stock', $d + ['department_id' => $this->dept->id, 'quantity' => 4, 'condition' => 'good']);
        $new(['item_master_id' => $masters['Bench'], 'is_new_purchase' => true])->assertStatus(422)->assertJsonValidationErrors('purchase_cost');
        $bench = $new(['item_master_id' => $masters['Bench'], 'is_new_purchase' => true, 'purchase_cost' => 12000, 'purchase_date' => '2026-06-20'])
            ->assertCreated()->assertJsonPath('data.item_code', 'DS-PHY-0001')->assertJsonPath('data.unit', 'piece')->json('data.id');
        $new(['item_master_id' => $masters['Microscope'], 'is_new_purchase' => false, 'purchase_cost' => 30000, 'condition' => 'repairable'])
            ->assertCreated()->assertJsonPath('data.item_code', 'DS-PHY-0002');
        // Only the new purchase is charged to the budget; switching it off removes the charge.
        $this->assertSame(['12000.00'], BudgetTransaction::query()->where('source_type', 'dead_stock')->pluck('amount')->all());
        $this->spa()->putJson("/api/v1/assets/dead-stock/{$bench}", ['department_id' => $this->dept->id, 'item_master_id' => $masters['Bench'], 'quantity' => 4, 'condition' => 'bad', 'is_new_purchase' => false, 'purchase_cost' => 12000])->assertOk();
        $this->assertSame(0, BudgetTransaction::query()->where('source_type', 'dead_stock')->count());

        $report = $this->spa()->getJson('/api/v1/assets/dead-stock/report')->assertOk()->json('data');
        $this->assertSame(42000.0, (float) $report['total_value']);
        $this->assertEqualsCanonicalizing(['bad', 'repairable'], array_column($report['by_condition'], 'key'));
        $this->spa()->getJson('/api/v1/assets/dead-stock?condition=bad')->assertJsonCount(1, 'data');
        $this->assertStringContainsString('DS-PHY-0002', $this->spa()->get('/api/v1/assets/dead-stock?format=csv')->streamedContent());

        // A department head works in their own department only, and cannot change the shared item master.
        $other = Department::factory()->for($this->org)->create();
        $hod = $this->userWithRole($this->org, ['assets.view', 'assets.manage'], PermissionScope::Departments, departmentIds: [$other->id]);
        $this->actingAs($hod);
        $this->spa()->getJson('/api/v1/assets/dead-stock')->assertJsonCount(0, 'data');
        $this->spa()->postJson('/api/v1/assets/dead-stock', ['department_id' => $this->dept->id, 'item_master_id' => $masters['Bench'], 'quantity' => 1, 'condition' => 'good', 'is_new_purchase' => false])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->spa()->deleteJson("/api/v1/assets/dead-stock/{$bench}")->assertNotFound();
        $this->spa()->postJson('/api/v1/assets/masters', ['item_name' => 'Fan'])->assertForbidden();
        $this->spa()->getJson('/api/v1/assets/allocations')->assertForbidden();
        $this->spa()->get('/api/v1/assets/dead-stock?format=csv')->assertForbidden();
    }

    public function test_items_are_given_returned_transferred_and_overdue_loans_are_reminded(): void
    {
        $this->actingAs($this->admin);
        [$a, $b] = [$this->userWithRole($this->org, []), $this->userWithRole($this->org, [])];
        Employee::factory()->in($this->dept)->create(['user_id' => $a->id, 'name' => 'Amol']);
        Employee::factory()->in($this->dept)->create(['user_id' => $b->id, 'name' => 'Vikas']);

        $laptop = $this->spa()->postJson('/api/v1/assets/allocation-types', ['name' => 'Laptop', 'is_loan' => false, 'fields' => [
            ['label' => 'Serial no.', 'type' => 'text', 'required' => true], ['label' => 'Charger', 'type' => 'dropdown', 'options' => ['Yes', 'No']],
        ]])->assertCreated()->assertJsonPath('data.fields.0.key', 'serial_no')->json('data.id');
        $keys = $this->spa()->postJson('/api/v1/assets/allocation-types', ['name' => 'Keys', 'is_loan' => true])->assertCreated()->json('data.id');
        $dell = $this->spa()->postJson('/api/v1/assets/allocation-items', ['type_id' => $laptop, 'name' => 'Dell #12'])->assertCreated()->json('data.id');
        $key = $this->spa()->postJson('/api/v1/assets/allocation-items', ['type_id' => $keys, 'name' => 'Lab key'])->assertCreated()->json('data.id');

        $give = fn (array $d) => $this->spa()->postJson('/api/v1/assets/allocations', $d);
        $give(['item_id' => $dell, 'user_id' => $a->id])->assertStatus(422)->assertJsonValidationErrors('custom.serial_no');
        $give(['item_id' => $dell, 'user_id' => $a->id, 'custom' => ['serial_no' => 'X1', 'charger' => 'Maybe']])->assertStatus(422)->assertJsonValidationErrors('custom.charger');
        $first = $give(['item_id' => $dell, 'user_id' => $a->id, 'custom' => ['serial_no' => 'X1', 'charger' => 'Yes'], 'purpose' => 'Teaching'])->assertCreated()->json('data.id');
        $give(['item_id' => $dell, 'user_id' => $b->id, 'custom' => ['serial_no' => 'X1']])->assertStatus(422)->assertJsonValidationErrors('item_id');
        $this->spa()->postJson("/api/v1/assets/allocation-items/{$dell}/retire")->assertStatus(422);

        // A transfer keeps the history: A's allocation closes, B's opens with the same details.
        $this->spa()->postJson("/api/v1/assets/allocations/{$first}/transfer", ['user_id' => $a->id])->assertStatus(422);
        $second = $this->spa()->postJson("/api/v1/assets/allocations/{$first}/transfer", ['user_id' => $b->id, 'reason' => 'Moved to lab'])->assertOk()
            ->assertJsonPath('data.user.id', $b->id)->assertJsonPath('data.custom.serial_no', 'X1')->assertJsonPath('data.transferred_from_id', $first)->json('data.id');
        $this->spa()->postJson("/api/v1/assets/allocations/{$second}/return", ['reason' => 'Left'])->assertOk();
        $this->spa()->postJson("/api/v1/assets/allocations/{$second}/return")->assertStatus(422);
        $this->spa()->getJson('/api/v1/assets/allocations?view=history')->assertJsonCount(2, 'data');
        $this->spa()->getJson('/api/v1/assets/allocations')->assertJsonCount(0, 'data');

        // A loan past its return time: the holder and the giver hear once a day.
        $give(['item_id' => $key, 'user_id' => $a->id, 'due_at' => now()->addHour()->toIso8601String()])->assertCreated();
        Carbon::setTestNow(now()->addHours(3));
        $this->artisan('erp:loan-overdue')->assertSuccessful();
        $this->artisan('erp:loan-overdue')->assertSuccessful();
        $types = fn (User $u) => UserNotification::withoutGlobalScopes()->where('user_id', $u->id)->orderBy('id')->pluck('type')->all();
        $this->assertSame(['assets.allocated', 'assets.allocated', 'assets.overdue'], $types($a));
        $this->assertSame(['assets.overdue_given'], $types($this->admin));
        $this->spa()->getJson('/api/v1/assets/allocations?view=overdue')->assertJsonCount(1, 'data')->assertJsonPath('meta.overdue', 1);
        $this->spa()->getJson('/api/v1/assets/allocations/report')->assertJsonPath('data.held', 1)->assertJsonPath('data.overdue', 1)->assertJsonPath('data.free_items', 1);

        $this->actingAs($a);
        $this->spa()->getJson('/api/v1/my/assets')->assertJsonCount(2, 'data')->assertJsonPath('data.0.overdue', true)
            ->assertJsonPath('data.1.custom.serial_no', 'X1')->assertJsonPath('data.1.fields.0.label', 'Serial no.');
        $this->spa()->getJson('/api/v1/assets/allocations')->assertForbidden();
        $this->assertSame(2, AssetAllocation::query()->where('user_id', $a->id)->count());
    }

    public function test_allocation_import_creates_types_and_items(): void
    {
        $this->actingAs($this->admin);
        $header = 'type_name,item_name,default_location,is_loan';
        $bad = UploadedFile::fake()->createWithContent('a.csv', "{$header}\nKeys,,Desk,yes\nKeys,Lab key,Desk,maybe\n");
        $rows = collect($this->spa()->post('/api/v1/assets/allocations/import/preview', ['file' => $bad], ['Accept' => 'application/json'])->assertOk()->json('data.rows'));
        $this->assertArrayHasKey('item_name', $rows[0]['errors']);
        $this->assertArrayHasKey('is_loan', $rows[1]['errors']);
        $good = UploadedFile::fake()->createWithContent('a.csv', "{$header}\nKeys,Lab key,Desk,हो\nKeys,Office key,Desk,yes\nLaptop,HP #3,,no\n");
        $this->spa()->post('/api/v1/assets/allocations/import', ['file' => $good], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.imported', 3);
        $types = collect($this->spa()->getJson('/api/v1/assets/allocation-types')->json('data'))->keyBy('name');
        $this->assertSame([true, 2], [$types['Keys']['is_loan'], $types['Keys']['items_count']]);
        $this->assertFalse($types['Laptop']['is_loan']);
    }
}
