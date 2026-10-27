<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\StoreItem;
use App\Services\AttendanceRules;
use App\Services\Store\StockLedger;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The store (store.view / store.manage): items, their stock and every
 * movement in and out. Purchases add stock through goods receipts.
 */
class StoreController extends Controller
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function items(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'low' => ['nullable', 'boolean'],
            'inactive' => ['nullable', 'boolean'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $balance = '(select coalesce(sum(sm.quantity), 0) from stock_movements sm where sm.item_id = store_items.id)';
        $items = StoreItem::query()->with('department:id,name')->select('store_items.*')->selectRaw("{$balance} as balance")
            ->when(! $request->boolean('inactive'), fn ($q) => $q->where('is_active', true))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('item_name', 'like', '%'.addcslashes($s, '%_\\').'%'))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($request->boolean('low'), fn ($q) => $q->where('reorder_level', '>', 0)->whereRaw("{$balance} <= store_items.reorder_level"))
            ->orderBy('item_name')->limit(5000)->get();

        if (($f['format'] ?? null) === 'csv') {
            AuditLogger::record('store', 'stock_export', null, null, ['rows' => $items->count()]);

            return Csv::download('store-stock.csv', ['item', 'category', 'department', 'unit', 'balance', 'reorder_level', 'active'],
                $items->map(fn (StoreItem $i) => [$i->item_name, $i->category, $i->department?->name, $i->unit, round((float) $i->balance, 2), (float) $i->reorder_level, $i->is_active ? 'yes' : 'no']));
        }

        return response()->json([
            'data' => $items->map(fn (StoreItem $i) => $this->item($i))->values(),
            'meta' => [
                'can_manage' => AccessContext::current()->can('store.manage'),
                'categories' => StoreItem::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
                'low_count' => StoreItem::query()->where('is_active', true)->where('reorder_level', '>', 0)->whereRaw("{$balance} <= store_items.reorder_level")->count(),
            ],
        ]);
    }

    public function show(StoreItem $item): JsonResponse
    {
        $movements = StockMovement::query()->where('item_id', $item->id)->with(['department:id,name', 'creator:id,name'])
            ->orderBy('movement_date')->orderBy('id')->get();
        $running = 0.0;
        $rows = $movements->map(function (StockMovement $m) use (&$running) {
            $running = round($running + (float) $m->quantity, 2);

            return [
                'id' => $m->id, 'movement_type' => $m->movement_type, 'quantity' => (float) $m->quantity, 'balance' => $running,
                'unit_cost' => $m->unit_cost === null ? null : (float) $m->unit_cost, 'department' => $m->department?->name, 'issued_to' => $m->issued_to,
                'from_purchase' => $m->source_type === 'goods_receipt_line', 'movement_date' => $m->movement_date->toDateString(), 'remarks' => $m->remarks,
                'by' => $m->creator?->name,
            ];
        });
        $item->setAttribute('balance', $running);

        return response()->json(['data' => $this->item($item->load('department:id,name')) + [
            // Newest first; the running balance is worked out oldest first.
            'movements' => $rows->reverse()->take(500)->values(),
            'can_manage' => AccessContext::current()->can('store.manage'),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $item = StoreItem::create($this->validated($request) + ['created_by' => $request->user()->id]);
        AuditLogger::record('store', 'item_create', $item, null, AuditLogger::snapshot($item));

        return $this->show($item)->setStatusCode(201);
    }

    public function update(Request $request, StoreItem $item): JsonResponse
    {
        $data = $this->validated($request, $item) + $request->validate(['is_active' => ['sometimes', 'boolean']]);
        $before = $item->getAttributes();
        $item->fill($data)->save();
        AuditLogger::recordChanges('store', 'item_update', $item, $before);

        return $this->show($item);
    }

    /** Receive into the store, issue out of it, or correct the count (adjust, signed). */
    public function move(Request $request, StoreItem $item): JsonResponse
    {
        $data = $request->validate([
            'movement_type' => ['required', Rule::in(StockMovement::TYPES)],
            'quantity' => ['required', 'numeric', 'not_in:0', 'min:-9999999', 'max:9999999'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('organization_id', AccessContext::current()->organizationId())],
            'issued_to' => ['nullable', 'string', 'max:255'],
            'movement_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'remarks' => ['nullable', 'string', 'max:500', 'required_if:movement_type,adjust'],
        ]);
        if (! $item->is_active) {
            throw ValidationException::withMessages(['item' => [__('store.item_inactive')]]);
        }
        $movement = $this->ledger->move($item, $data['movement_type'], (float) $data['quantity'], [
            'unit_cost' => $data['movement_type'] === 'receive' ? ($data['unit_cost'] ?? null) : null,
            'department_id' => $data['department_id'] ?? null, 'issued_to' => $data['issued_to'] ?? null,
            'movement_date' => $data['movement_date'] ?? $this->today(), 'remarks' => $data['remarks'] ?? null,
        ], $request->user()->id);
        AuditLogger::record('store', 'stock_'.$movement->movement_type, $item, null, ['quantity' => (float) $movement->quantity, 'movement' => $movement->id]);

        return $this->show($item);
    }

    /** What the store gave out in a period, by department and item (issues only). */
    public function issuesReport(Request $request): JsonResponse
    {
        $f = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $rows = DB::table('stock_movements as sm')->join('store_items as si', 'si.id', '=', 'sm.item_id')->leftJoin('departments as d', 'd.id', '=', 'sm.department_id')
            ->where('sm.organization_id', AccessContext::current()->organizationId())->where('sm.movement_type', 'issue')
            ->whereBetween('sm.movement_date', [$f['from'], $f['to']])
            ->groupBy('sm.department_id', 'd.name', 'si.id', 'si.item_name', 'si.unit')
            ->orderBy('d.name')->orderBy('si.item_name')
            ->get(['sm.department_id', 'd.name as department', 'si.id as item_id', 'si.item_name', 'si.unit', DB::raw('-sum(sm.quantity) as quantity'), DB::raw('count(*) as issues')]);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'department_id' => $r->department_id, 'department' => $r->department, 'item_id' => (int) $r->item_id, 'item_name' => $r->item_name, 'unit' => $r->unit,
            'quantity' => round((float) $r->quantity, 2), 'issues' => (int) $r->issues,
        ])->values()]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?StoreItem $item = null): array
    {
        $orgId = AccessContext::current()->organizationId();

        $data = $request->validate([
            'item_name' => ['required', 'string', 'max:255', Rule::unique('store_items', 'item_name')->where('organization_id', $orgId)->ignore($item?->id)],
            'category' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'unit' => ['required', 'string', 'max:30'],
            'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);
        $data['reorder_level'] = (float) ($data['reorder_level'] ?? 0);

        return $data;
    }

    /** @return array<string, mixed> */
    private function item(StoreItem $i): array
    {
        $balance = round((float) ($i->balance ?? 0), 2);

        return [
            'id' => $i->id, 'item_name' => $i->item_name, 'category' => $i->category, 'unit' => $i->unit, 'reorder_level' => (float) $i->reorder_level,
            'department' => $i->department ? ['id' => $i->department->id, 'name' => $i->department->name] : null, 'is_active' => $i->is_active,
            'balance' => $balance, 'low' => (float) $i->reorder_level > 0 && $balance <= (float) $i->reorder_level,
        ];
    }

    private function today(): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
    }
}
