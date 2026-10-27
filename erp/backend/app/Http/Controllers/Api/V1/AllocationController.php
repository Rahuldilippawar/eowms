<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionScope;
use App\Http\Controllers\Controller;
use App\Models\AllocationItem;
use App\Models\AllocationType;
use App\Models\AssetAllocation;
use App\Services\Assets\AllocationDesk;
use App\Services\Assets\AllocationImport;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The issue / return register (erp/docs/ASSETS.md): types with their own
 * fields, items of each type, and who holds what. Everyone sees what they
 * hold themselves at /my/assets. The register is the organization's, so
 * it needs assets.view / assets.manage organization-wide.
 */
class AllocationController extends Controller
{
    public function __construct(private readonly AllocationDesk $desk) {}

    // Types and items

    public function types(Request $request): JsonResponse
    {
        $this->organizationWide('assets.view');
        $types = AllocationType::query()->when(! $request->boolean('inactive'), fn ($q) => $q->where('is_active', true))
            ->withCount(['items' => fn ($q) => $q->where('is_active', true)])->orderBy('is_loan')->orderBy('name')->get();

        return response()->json([
            'data' => $types->map(fn (AllocationType $t) => $this->type($t) + ['items_count' => $t->items_count])->values(),
            'meta' => ['can_manage' => $this->isOrganizationWide('assets.manage')],
        ]);
    }

    public function storeType(Request $request): JsonResponse
    {
        $this->organizationWide('assets.manage');
        $type = AllocationType::create($this->typeData($request));
        AuditLogger::record('assets', 'allocation_type_create', $type, null, AuditLogger::snapshot($type));

        return response()->json(['data' => $this->type($type)], 201);
    }

    public function updateType(Request $request, AllocationType $type): JsonResponse
    {
        $this->organizationWide('assets.manage');
        $before = $type->getAttributes();
        $type->fill($this->typeData($request, $type) + $request->validate(['is_active' => ['sometimes', 'boolean']]))->save();
        AuditLogger::recordChanges('assets', 'allocation_type_update', $type, $before);

        return response()->json(['data' => $this->type($type)]);
    }

    public function items(Request $request): JsonResponse
    {
        $this->organizationWide('assets.view');
        $f = $request->validate(['type_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100'], 'free' => ['nullable', 'boolean']]);
        $items = AllocationItem::query()->where('is_active', true)->with(['type:id,name,is_loan', 'current.user:id,name'])
            ->when($f['type_id'] ?? null, fn ($q, $id) => $q->where('type_id', $id))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.addcslashes($s, '%_\\').'%'))
            ->when($request->boolean('free'), fn ($q) => $q->whereDoesntHave('current'))
            ->orderBy('type_id')->orderBy('name')->limit(2000)->get();

        return response()->json(['data' => $items->map(fn (AllocationItem $i) => [
            'id' => $i->id, 'name' => $i->name, 'default_location' => $i->default_location,
            'type' => ['id' => $i->type->id, 'name' => $i->type->name, 'is_loan' => $i->type->is_loan],
            'held_by' => $i->current?->user ? ['id' => $i->current->user->id, 'name' => $i->current->user->name] : null,
        ])->values()]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $this->organizationWide('assets.manage');
        $data = $request->validate([
            'type_id' => ['required', 'integer', Rule::exists('allocation_types', 'id')->where('organization_id', AccessContext::current()->organizationId())->where('is_active', true)],
            'name' => ['required', 'string', 'max:255', Rule::unique('allocation_items', 'name')->where('type_id', $request->input('type_id'))],
            'default_location' => ['nullable', 'string', 'max:255'],
        ]);
        $item = AllocationItem::create($data);
        AuditLogger::record('assets', 'allocation_item_create', $item);

        return response()->json(['data' => ['id' => $item->id, 'name' => $item->name]], 201);
    }

    /** An item no longer used; one still with someone cannot be retired. */
    public function retireItem(AllocationItem $item): JsonResponse
    {
        $this->organizationWide('assets.manage');
        if (AssetAllocation::query()->where('item_id', $item->id)->whereNull('returned_at')->exists()) {
            throw ValidationException::withMessages(['item' => [__('assets.retire_held')]]);
        }
        $item->forceFill(['is_active' => false])->save();
        AuditLogger::record('assets', 'allocation_item_retire', $item);

        return response()->json(['data' => ['id' => $item->id, 'is_active' => false]]);
    }

    // Allocations

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $this->organizationWide('assets.view');
        $f = $request->validate([
            'view' => ['nullable', Rule::in(['current', 'loans', 'overdue', 'history'])],
            'type_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $view = $f['view'] ?? 'current';
        $query = AssetAllocation::query()->with(['type:id,name,is_loan,fields', 'item:id,name', 'user:id,name', 'allocator:id,name', 'receiver:id,name'])
            ->when($view !== 'history', fn ($q) => $q->whereNull('returned_at'))
            ->when($view === 'loans', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('due_at')->orWhereHas('type', fn ($t) => $t->where('is_loan', true))))
            ->when($view === 'overdue', fn ($q) => $q->whereNotNull('due_at')->where('due_at', '<', now()))
            ->when($f['type_id'] ?? null, fn ($q, $id) => $q->where('type_id', $id))
            ->when($f['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->whereHas('item', fn ($i) => $i->where('name', 'like', $like))->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)));
            })
            ->orderByDesc('allocated_at')->orderByDesc('id');

        if (($f['format'] ?? null) === 'csv') {
            abort_unless(AccessContext::current()->can('assets.export'), 403, __('auth.forbidden'));
            $rows = $query->limit(20000)->get();
            AuditLogger::record('assets', 'allocation_export', null, null, ['rows' => $rows->count()]);

            return Csv::download('allocations.csv', ['type', 'loan', 'item', 'holder', 'purpose', 'given_at', 'due_at', 'returned_at', 'returned_to', 'reason', 'given_by'],
                $rows->map(fn (AssetAllocation $a) => [$a->type?->name, $a->type?->is_loan ? 'yes' : 'no', $a->item?->name, $a->user?->name, $a->purpose,
                    $a->allocated_at->toDateTimeString(), $a->due_at?->toDateTimeString(), $a->returned_at?->toDateTimeString(), $a->receiver?->name, $a->return_reason, $a->allocator?->name]));
        }
        $page = $query->paginate(50)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (AssetAllocation $a) => $this->allocation($a))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(), 'can_manage' => $this->isOrganizationWide('assets.manage'),
                'overdue' => AssetAllocation::query()->whereNull('returned_at')->whereNotNull('due_at')->where('due_at', '<', now())->count()],
        ]);
    }

    public function allocate(Request $request): JsonResponse
    {
        $this->organizationWide('assets.manage');
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'allocated_at' => ['nullable', 'date', 'before_or_equal:now'],
            'due_at' => ['nullable', 'date', 'after:now'],
            'custom' => ['nullable', 'array'],
        ]);
        $item = AllocationItem::query()->where('is_active', true)->with('type')->findOrFail($data['item_id']);
        $allocation = $this->desk->allocate($item, (int) $data['user_id'], [
            'custom' => $this->desk->custom($item->type, $data['custom'] ?? []), 'purpose' => $data['purpose'] ?? null,
            'allocated_at' => isset($data['allocated_at']) ? Carbon::parse($data['allocated_at']) : now(),
            'due_at' => isset($data['due_at']) ? Carbon::parse($data['due_at']) : null,
        ], $request->user()->id);
        AuditLogger::record('assets', 'allocate', $allocation, null, ['item' => $item->name, 'user' => $allocation->user_id]);

        return response()->json(['data' => $this->allocation($allocation->load(['type', 'item', 'user:id,name', 'allocator:id,name']))], 201);
    }

    public function giveBack(Request $request, AssetAllocation $allocation): JsonResponse
    {
        $this->organizationWide('assets.manage');
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $this->desk->giveBack($allocation, $reason, $request->user()->id);
        AuditLogger::record('assets', 'return', $allocation, null, ['reason' => $reason]);

        return response()->json(['data' => $this->allocation($allocation->load(['type', 'item', 'user:id,name', 'allocator:id,name', 'receiver:id,name']))]);
    }

    public function transfer(Request $request, AssetAllocation $allocation): JsonResponse
    {
        $this->organizationWide('assets.manage');
        $data = $request->validate(['user_id' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:500']]);
        $next = $this->desk->transfer($allocation, (int) $data['user_id'], $data['reason'] ?? null, $request->user()->id);
        AuditLogger::record('assets', 'transfer', $next, null, ['from' => $allocation->user_id, 'to' => $next->user_id]);

        return response()->json(['data' => $this->allocation($next->load(['type', 'item', 'user:id,name', 'allocator:id,name']))]);
    }

    /** What the signed-in person holds now, and what they held before. */
    public function mine(Request $request): JsonResponse
    {
        $rows = AssetAllocation::query()->where('user_id', $request->user()->id)->with(['type:id,name,is_loan', 'item:id,name', 'allocator:id,name', 'receiver:id,name'])
            ->orderByRaw('returned_at is null desc')->orderByDesc('allocated_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (AssetAllocation $a) => $this->allocation($a))->values()]);
    }

    public function report(): JsonResponse
    {
        $this->organizationWide('assets.view');
        $open = AssetAllocation::query()->whereNull('returned_at');
        $byType = (clone $open)->join('allocation_types as t', 't.id', '=', 'asset_allocations.type_id')->groupBy('t.id', 't.name')
            ->orderByDesc(DB::raw('count(*)'))->get(['t.name', DB::raw('count(*) as count')]);
        $byHolder = (clone $open)->join('users as u', 'u.id', '=', 'asset_allocations.user_id')->groupBy('u.id', 'u.name')
            ->orderByDesc(DB::raw('count(*)'))->limit(20)->get(['u.name', DB::raw('count(*) as count')]);

        return response()->json(['data' => [
            'held' => (clone $open)->count(),
            'overdue' => (clone $open)->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'free_items' => AllocationItem::query()->where('is_active', true)->whereDoesntHave('current')->count(),
            'given_last_30_days' => AssetAllocation::query()->where('allocated_at', '>=', now()->subDays(30))->count(),
            'by_type' => $byType->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count])->values(),
            'by_holder' => $byHolder->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count])->values(),
        ]]);
    }

    public function importTemplate(): StreamedResponse
    {
        $this->organizationWide('assets.manage');

        return Csv::download('allocation-import-template.csv', AllocationImport::COLUMNS, [['Laptop', 'Dell Latitude #12', 'Office', 'no'], ['Room keys', 'Lab 2 key', 'Security desk', 'yes']]);
    }

    public function importPreview(Request $request, AllocationImport $import): JsonResponse
    {
        $this->organizationWide('assets.manage');
        $checked = $import->check($this->rows($request));

        return response()->json(['data' => ['rows' => $checked, 'total' => count($checked), 'with_errors' => collect($checked)->filter(fn ($r) => $r['errors'] !== [])->count()]]);
    }

    public function import(Request $request, AllocationImport $import): JsonResponse
    {
        $this->organizationWide('assets.manage');
        $checked = $import->check($this->rows($request));
        $bad = collect($checked)->filter(fn ($r) => $r['errors'] !== [])->values();
        if ($bad->isNotEmpty()) {
            return response()->json(['message' => __('import.has_errors'), 'errors' => ['file' => [__('import.has_errors')]], 'rows' => $bad], 422);
        }

        return response()->json(['data' => ['imported' => $import->import($checked)]], 201);
    }

    /** The register covers the whole organization, so it needs the permission organization-wide. */
    private function organizationWide(string $permission): void
    {
        abort_unless($this->isOrganizationWide($permission), 403, __('auth.forbidden'));
    }

    private function isOrganizationWide(string $permission): bool
    {
        return AccessContext::current()->scope($permission)?->scope === PermissionScope::Organization;
    }

    /** @return list<array<string, string>> */
    private function rows(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);

        return CsvReader::rows($request->file('file'), AllocationImport::REQUIRED, 2000);
    }

    /** @return array<string, mixed> */
    private function typeData(Request $request, ?AllocationType $type = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('allocation_types', 'name')->where('organization_id', AccessContext::current()->organizationId())->ignore($type?->id)],
            'is_loan' => ['required', 'boolean'],
            'fields' => ['nullable', 'array', 'max:20'],
            'fields.*.label' => ['required', 'string', 'max:100'],
            'fields.*.type' => ['required', Rule::in(AllocationDesk::FIELD_TYPES)],
            'fields.*.options' => ['nullable', 'array', 'max:50', 'required_if:fields.*.type,dropdown'],
            'fields.*.options.*' => ['string', 'max:100'],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.key' => ['nullable', 'string', 'max:60'],
        ]);
        // Keys stay stable when a field is renamed, so values already saved keep their label.
        $used = [];
        $data['fields'] = collect($data['fields'] ?? [])->map(function ($f) use (&$used) {
            $key = ($f['key'] ?? '') !== '' ? $f['key'] : (Str::slug($f['label'], '_') ?: 'field');
            while (in_array($key, $used, true)) {
                $key .= '_2';
            }
            $used[] = $key;

            return ['key' => $key, 'label' => $f['label'], 'type' => $f['type'], 'options' => $f['type'] === 'dropdown' ? array_values($f['options'] ?? []) : [],
                'required' => (bool) ($f['required'] ?? false)];
        })->values()->all();

        return $data;
    }

    /** @return array<string, mixed> */
    private function type(AllocationType $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'is_loan' => $t->is_loan, 'fields' => $t->fields ?? [], 'is_active' => $t->is_active];
    }

    /** @return array<string, mixed> */
    private function allocation(AssetAllocation $a): array
    {
        return [
            'id' => $a->id,
            'type' => $a->type ? ['id' => $a->type->id, 'name' => $a->type->name, 'is_loan' => $a->type->is_loan] : null,
            'item' => $a->item ? ['id' => $a->item->id, 'name' => $a->item->name] : null,
            'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
            'custom' => $a->custom ?? (object) [],
            'fields' => $a->type?->fields ?? [],
            'purpose' => $a->purpose,
            'allocated_at' => $a->allocated_at->toIso8601String(),
            'due_at' => $a->due_at?->toIso8601String(),
            'overdue' => $a->returned_at === null && $a->due_at !== null && $a->due_at->isPast(),
            'returned_at' => $a->returned_at?->toIso8601String(),
            'returned_to' => $a->receiver?->name,
            'return_reason' => $a->return_reason,
            'allocated_by' => $a->allocator?->name,
            'transferred_from_id' => $a->transferred_from_id,
        ];
    }
}
