<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionScope;
use App\Http\Controllers\Controller;
use App\Models\AssetItemMaster;
use App\Models\DeadStockItem;
use App\Models\Department;
use App\Services\Assets\ItemMasterImport;
use App\Services\AttendanceRules;
use App\Services\Finance\BudgetLedger;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use App\Support\Numbering\NumberSequence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dead stock (erp/docs/ASSETS.md): each department's register of lasting
 * assets, picked from the organization's item master. A new purchase is
 * charged to the department budget; an existing asset is only recorded.
 */
class DeadStockController extends Controller
{
    public function __construct(private readonly BudgetLedger $ledger) {}

    // Item master

    public function masters(Request $request): JsonResponse
    {
        $inactive = $request->boolean('inactive');

        return response()->json([
            'data' => AssetItemMaster::query()->when(! $inactive, fn ($q) => $q->where('is_active', true))->orderBy('category')->orderBy('item_name')->limit(5000)
                ->get(['id', 'item_name', 'category', 'default_unit', 'is_active']),
            'meta' => ['can_manage' => AccessContext::current()->scope('assets.manage')?->scope === PermissionScope::Organization],
        ]);
    }

    public function storeMaster(Request $request): JsonResponse
    {
        // The item master is the organization's one list.
        abort_unless(AccessContext::current()->scope('assets.manage')?->scope === PermissionScope::Organization, 403, __('auth.forbidden'));
        $master = AssetItemMaster::create($this->masterData($request));
        AuditLogger::record('assets', 'item_master_create', $master, null, AuditLogger::snapshot($master));

        return response()->json(['data' => $master->only(['id', 'item_name', 'category', 'default_unit', 'is_active'])], 201);
    }

    public function updateMaster(Request $request, AssetItemMaster $master): JsonResponse
    {
        // The item master is the organization's one list.
        abort_unless(AccessContext::current()->scope('assets.manage')?->scope === PermissionScope::Organization, 403, __('auth.forbidden'));
        $before = $master->getAttributes();
        $master->fill($this->masterData($request, $master) + $request->validate(['is_active' => ['sometimes', 'boolean']]))->save();
        AuditLogger::recordChanges('assets', 'item_master_update', $master, $before);

        return response()->json(['data' => $master->only(['id', 'item_name', 'category', 'default_unit', 'is_active'])]);
    }

    public function masterTemplate(): StreamedResponse
    {
        return Csv::download('asset-item-master-template.csv', ItemMasterImport::COLUMNS, [['Steel cupboard', 'Furniture', 'piece'], ['Projector', 'Electronics', 'piece']]);
    }

    public function masterPreview(Request $request, ItemMasterImport $import): JsonResponse
    {
        // The item master is the organization's one list.
        abort_unless(AccessContext::current()->scope('assets.manage')?->scope === PermissionScope::Organization, 403, __('auth.forbidden'));
        $checked = $import->check($this->rows($request));

        return response()->json(['data' => ['rows' => $checked, 'total' => count($checked), 'with_errors' => collect($checked)->filter(fn ($r) => $r['errors'] !== [])->count()]]);
    }

    public function masterImport(Request $request, ItemMasterImport $import): JsonResponse
    {
        // The item master is the organization's one list.
        abort_unless(AccessContext::current()->scope('assets.manage')?->scope === PermissionScope::Organization, 403, __('auth.forbidden'));
        $checked = $import->check($this->rows($request));
        $bad = collect($checked)->filter(fn ($r) => $r['errors'] !== [])->values();
        if ($bad->isNotEmpty()) {
            return response()->json(['message' => __('import.has_errors'), 'errors' => ['file' => [__('import.has_errors')]], 'rows' => $bad], 422);
        }

        return response()->json(['data' => ['imported' => $import->import($checked)]], 201);
    }

    // Register

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'department_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:100'],
            'condition' => ['nullable', Rule::in(DeadStockItem::CONDITIONS)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $query = $this->scoped('assets.view')->with('department:id,name,code')
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($f['condition'] ?? null, fn ($q, $c) => $q->where('condition', $c))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('item_name', 'like', $like)->orWhere('item_code', 'like', $like)->orWhere('location', 'like', $like));
            })
            ->orderBy('department_id')->orderBy('item_code');

        if (($f['format'] ?? null) === 'csv') {
            abort_unless(AccessContext::current()->can('assets.export'), 403, __('auth.forbidden'));
            $rows = $query->limit(20000)->get();
            AuditLogger::record('assets', 'dead_stock_export', null, null, ['rows' => $rows->count()]);

            return Csv::download('dead-stock.csv', ['code', 'department', 'item', 'category', 'quantity', 'unit', 'purchase_date', 'purchase_cost', 'new_purchase', 'condition', 'location', 'remarks'],
                $rows->map(fn (DeadStockItem $d) => [$d->item_code, $d->department?->name, $d->item_name, $d->category, $d->quantity, $d->unit, $d->purchase_date?->toDateString(),
                    $d->purchase_cost, $d->is_new_purchase ? 'yes' : 'no', $d->condition, $d->location, $d->remarks]));
        }
        $page = $query->paginate(50)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (DeadStockItem $d) => $this->present($d))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(), 'can_manage' => AccessContext::current()->can('assets.manage'),
                'categories' => AssetItemMaster::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $item = DB::transaction(function () use ($data, $request) {
            $code = Department::query()->whereKey($data['department_id'])->value('code') ?: 'GEN';
            $n = NumberSequence::next(AccessContext::current()->organizationId(), 'dead_stock', mb_strtoupper($code));
            $item = DeadStockItem::create($data + ['item_code' => sprintf('DS-%s-%04d', mb_strtoupper($code), $n), 'added_by' => $request->user()->id]);
            $this->charge($item, $request->user()->id);
            AuditLogger::record('assets', 'dead_stock_create', $item, null, AuditLogger::snapshot($item));

            return $item;
        });

        return response()->json(['data' => $this->present($item->load('department:id,name,code'))], 201);
    }

    public function update(Request $request, DeadStockItem $item): JsonResponse
    {
        $item = $this->scoped('assets.manage')->findOrFail($item->id);
        $data = $this->validated($request);
        DB::transaction(function () use ($item, $data, $request) {
            $before = $item->getAttributes();
            $item->fill($data)->save();
            $this->charge($item, $request->user()->id);
            AuditLogger::recordChanges('assets', 'dead_stock_update', $item, $before);
        });

        return response()->json(['data' => $this->present($item->load('department:id,name,code'))]);
    }

    public function destroy(DeadStockItem $item): JsonResponse
    {
        $item = $this->scoped('assets.manage')->findOrFail($item->id);
        DB::transaction(function () use ($item) {
            AuditLogger::record('assets', 'dead_stock_delete', $item, AuditLogger::snapshot($item));
            $this->ledger->reverse('dead_stock', $item->id);
            $item->delete();
        });

        return response()->json(['data' => ['id' => $item->id, 'deleted' => true]]);
    }

    /** Counts and value by department, category and condition. */
    public function report(): JsonResponse
    {
        $base = $this->scoped('assets.view')->toBase();
        $group = fn (string $column) => (clone $base)->groupBy($column)->orderBy($column)
            ->get([$column.' as key', DB::raw('count(*) as entries'), DB::raw('sum(quantity) as quantity'), DB::raw('sum(coalesce(purchase_cost, 0)) as value')]);
        $departments = Department::query()->pluck('name', 'id');
        $row = fn ($r, $label) => ['key' => $r->key, 'label' => $label, 'entries' => (int) $r->entries, 'quantity' => (int) $r->quantity, 'value' => round((float) $r->value, 2)];

        return response()->json(['data' => [
            'by_department' => $group('department_id')->map(fn ($r) => $row($r, $departments[$r->key] ?? null))->values(),
            'by_category' => $group('category')->map(fn ($r) => $row($r, $r->key))->values(),
            'by_condition' => $group('condition')->map(fn ($r) => $row($r, $r->key))->values(),
            'total_value' => round((float) (clone $base)->sum('purchase_cost'), 2),
        ]]);
    }

    /** A new purchase with a cost is charged to the department budget in the year of its date. */
    private function charge(DeadStockItem $item, int $userId): void
    {
        $amount = $item->is_new_purchase ? (float) $item->purchase_cost : 0.0;
        $this->ledger->record(Department::query()->findOrFail($item->department_id), $amount, 'purchase', 'dead_stock', $item->id,
            "{$item->item_name} ({$item->item_code})", $item->purchase_date?->toDateString() ?? $this->today(), $userId);
    }

    /** @return Builder<DeadStockItem> */
    private function scoped(string $permission): Builder
    {
        $ids = ScopeDepartments::ids(AccessContext::current()->scope($permission));

        return DeadStockItem::query()->when($ids !== null, fn ($q) => $q->whereIn('department_id', $ids ?: [0]));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'item_master_id' => ['required', 'integer', Rule::exists('asset_item_masters', 'id')->where('organization_id', $orgId)->where('is_active', true)],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit' => ['nullable', 'string', 'max:30'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'required_if:is_new_purchase,true,1'],
            'is_new_purchase' => ['required', 'boolean'],
            'condition' => ['required', Rule::in(DeadStockItem::CONDITIONS)],
            'location' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        if (! ScopeDepartments::reaches(AccessContext::current()->scope('assets.manage'), (int) $data['department_id'])) {
            throw ValidationException::withMessages(['department_id' => [__('access.outside_scope')]]);
        }
        $master = AssetItemMaster::query()->findOrFail($data['item_master_id']);

        return $data + [
            'item_name' => $master->item_name, 'category' => $master->category, 'unit' => $data['unit'] ?? $master->default_unit,
            'institution_id' => Department::query()->whereKey($data['department_id'])->value('institution_id'),
        ];
    }

    /** @return array<string, mixed> */
    private function masterData(Request $request, ?AssetItemMaster $master = null): array
    {
        return $request->validate([
            'item_name' => ['required', 'string', 'max:255', Rule::unique('asset_item_masters', 'item_name')->where('organization_id', AccessContext::current()->organizationId())->ignore($master?->id)],
            'category' => ['nullable', 'string', 'max:100'],
            'default_unit' => ['nullable', 'string', 'max:30'],
        ]);
    }

    /** @return list<array<string, string>> */
    private function rows(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);

        return CsvReader::rows($request->file('file'), ItemMasterImport::REQUIRED, 2000);
    }

    /** @return array<string, mixed> */
    private function present(DeadStockItem $d): array
    {
        return [
            'id' => $d->id, 'item_code' => $d->item_code, 'item_master_id' => $d->item_master_id, 'item_name' => $d->item_name, 'category' => $d->category,
            'department' => $d->department ? ['id' => $d->department->id, 'name' => $d->department->name] : null,
            'quantity' => $d->quantity, 'unit' => $d->unit, 'purchase_date' => $d->purchase_date?->toDateString(),
            'purchase_cost' => $d->purchase_cost === null ? null : (float) $d->purchase_cost, 'is_new_purchase' => $d->is_new_purchase,
            'condition' => $d->condition, 'location' => $d->location, 'remarks' => $d->remarks,
            'can_manage' => ScopeDepartments::reaches(AccessContext::current()->scope('assets.manage'), $d->department_id),
        ];
    }

    private function today(): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
    }
}
