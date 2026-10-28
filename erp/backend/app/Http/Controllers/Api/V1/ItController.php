<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ItAsset;
use App\Models\ItAssetTransfer;
use App\Models\ItLab;
use App\Models\ItRepairTicket;
use App\Services\AttendanceRules;
use App\Services\It\ItAccess;
use App\Services\It\ItAssetImport;
use App\Services\It\ItBook;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The IT department (erp/docs/IT.md): labs, their assets, repair tickets
 * and transfers. Anyone signed in can look an asset up by its tag and
 * report a problem with it.
 */
class ItController extends Controller
{
    public function __construct(private readonly ItAccess $access, private readonly ItBook $book) {}

    public function dashboard(): JsonResponse
    {
        $this->mayEnter();
        $assets = $this->access->scope(ItAsset::query());
        $today = $this->today();
        $soon = now()->addDays(30)->toDateString();
        $labs = $this->access->scope(ItLab::query()->where('is_active', true), 'id')->with('assistant:id,name')->orderBy('name')
            ->withCount(['assets', 'assets as working_count' => fn ($q) => $q->where('status', 'working'), 'assets as issue_count' => fn ($q) => $q->whereIn('status', ['faulty', 'under_repair'])])
            ->get();

        return response()->json(['data' => [
            'labs' => $labs->map(fn (ItLab $l) => $this->lab($l) + ['assets_count' => $l->assets_count, 'working_count' => $l->working_count, 'issue_count' => $l->issue_count])->values(),
            'by_status' => (clone $assets)->groupBy('status')->selectRaw('status, count(*) as total')->get()->mapWithKeys(fn ($r) => [$r->status => (int) $r->total]),
            'open_tickets' => $this->access->scope(ItRepairTicket::query())->where('status', '!=', 'resolved')->count(),
            'smartboards' => [
                'total' => (clone $assets)->where('asset_type', 'smartboard')->where('status', '!=', 'scrapped')->count(),
                'on' => (clone $assets)->where('asset_type', 'smartboard')->where('power_status', 'on')->count(),
                'faulty' => (clone $assets)->where('asset_type', 'smartboard')->whereIn('status', ['faulty', 'under_repair'])->count(),
            ],
            'expiring' => (clone $assets)->where('status', '!=', 'scrapped')
                ->where(fn ($q) => $q->whereBetween('warranty_expiry', [$today, $soon])->orWhereBetween('license_expiry', [$today, $soon]))
                ->with('lab:id,name')->orderBy('asset_tag')->limit(50)->get()
                ->map(fn (ItAsset $a) => ['id' => $a->id, 'asset_tag' => $a->asset_tag, 'lab' => $a->lab?->name, 'warranty_expiry' => $a->warranty_expiry?->toDateString(), 'license_expiry' => $a->license_expiry?->toDateString()])->values(),
            'can_manage' => $this->access->canManage(),
        ]]);
    }

    // Labs

    public function labs(): JsonResponse
    {
        $this->mayEnter();

        return response()->json(['data' => $this->access->scope(ItLab::query(), 'id')->with(['assistant:id,name', 'department:id,name'])->orderBy('name')->get()
            ->map(fn (ItLab $l) => $this->lab($l))->values()]);
    }

    public function storeLab(Request $request): JsonResponse
    {
        $lab = ItLab::create($this->labData($request));
        AuditLogger::record('it', 'lab_create', $lab, null, AuditLogger::snapshot($lab));

        return response()->json(['data' => $this->lab($lab->load(['assistant:id,name', 'department:id,name']))], 201);
    }

    public function updateLab(Request $request, ItLab $lab): JsonResponse
    {
        $before = $lab->getAttributes();
        $lab->fill($this->labData($request, $lab) + $request->validate(['is_active' => ['sometimes', 'boolean']]))->save();
        AuditLogger::recordChanges('it', 'lab_update', $lab, $before);

        return response()->json(['data' => $this->lab($lab->load(['assistant:id,name', 'department:id,name']))]);
    }

    // Assets

    public function assets(Request $request): JsonResponse|StreamedResponse
    {
        $this->mayEnter();
        $f = $request->validate([
            'lab_id' => ['nullable', 'integer'],
            'asset_type' => ['nullable', Rule::in(ItAsset::TYPES)],
            'status' => ['nullable', Rule::in(ItAsset::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $query = $this->access->scope(ItAsset::query())->with('lab:id,name')
            ->withCount(['tickets as open_tickets' => fn ($q) => $q->where('status', '!=', 'resolved')])
            ->when($f['lab_id'] ?? null, fn ($q, $id) => $q->where('lab_id', $id))
            ->when($f['asset_type'] ?? null, fn ($q, $t) => $q->where('asset_type', $t))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('asset_tag', 'like', $like)->orWhere('serial_no', 'like', $like)->orWhere('brand', 'like', $like)
                    ->orWhere('model', 'like', $like)->orWhere('workstation_no', 'like', $like));
            })
            ->orderBy('lab_id')->orderBy('workstation_no')->orderBy('asset_tag');

        if (($f['format'] ?? null) === 'csv') {
            $rows = $query->limit(20000)->get();
            AuditLogger::record('it', 'assets_export', null, null, ['rows' => $rows->count()]);

            return Csv::download('it-assets.csv', ['tag', 'lab', 'workstation', 'type', 'brand', 'model', 'serial_no', 'purchase_date', 'purchase_cost', 'warranty_expiry', 'os_license', 'license_expiry', 'status', 'power'],
                $rows->map(fn (ItAsset $a) => [$a->asset_tag, $a->lab?->name, $a->workstation_no, $a->asset_type, $a->brand, $a->model, $a->serial_no, $a->purchase_date?->toDateString(),
                    $a->purchase_cost, $a->warranty_expiry?->toDateString(), $a->os_license, $a->license_expiry?->toDateString(), $a->status, $a->power_status]));
        }
        $page = $query->paginate(50)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (ItAsset $a) => $this->asset($a))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(), 'can_manage' => $this->access->canManage()],
        ]);
    }

    public function show(ItAsset $asset): JsonResponse
    {
        $asset = $this->visible($asset);

        return response()->json(['data' => $this->assetDetail($asset)]);
    }

    public function storeAsset(Request $request): JsonResponse
    {
        $data = $this->assetData($request);
        $lab = ItLab::query()->where('is_active', true)->findOrFail($data['lab_id']);
        abort_unless($this->access->canKeepLab($lab->id), 403, __('auth.forbidden'));
        $asset = $this->book->create($lab, collect($data)->except('lab_id')->all(), $request->user()->id);
        AuditLogger::record('it', 'asset_create', $asset, null, AuditLogger::snapshot($asset));

        return response()->json(['data' => $this->assetDetail($asset)], 201);
    }

    public function updateAsset(Request $request, ItAsset $asset): JsonResponse
    {
        $asset = $this->kept($asset);
        $data = collect($this->assetData($request, false))->except(['lab_id', 'asset_type'])->all();
        $before = $asset->getAttributes();
        $asset->fill($data)->save();
        AuditLogger::recordChanges('it', 'asset_update', $asset, $before);

        return response()->json(['data' => $this->assetDetail($asset)]);
    }

    public function setStatus(Request $request, ItAsset $asset): JsonResponse
    {
        $asset = $this->kept($asset);
        $data = $request->validate(['status' => ['nullable', Rule::in(ItAsset::STATUSES)], 'power_status' => ['nullable', Rule::in(['on', 'off'])]]);
        $before = $asset->getAttributes();
        if (isset($data['status'])) {
            $asset->status = $data['status'];
        }
        if (isset($data['power_status'])) {
            $asset->forceFill(['power_status' => $data['power_status'], 'power_updated_at' => now(), 'power_updated_by' => $request->user()->id]);
        }
        $asset->save();
        AuditLogger::recordChanges('it', 'asset_status', $asset, $before);

        return response()->json(['data' => $this->assetDetail($asset)]);
    }

    public function transfer(Request $request, ItAsset $asset): JsonResponse
    {
        $data = $request->validate(['to_lab_id' => ['required', 'integer'], 'remarks' => ['nullable', 'string', 'max:255']]);
        $to = ItLab::query()->where('is_active', true)->findOrFail($data['to_lab_id']);
        $this->book->transfer($asset, $to, $data['remarks'] ?? null, $request->user()->id);
        AuditLogger::record('it', 'asset_transfer', $asset, null, ['to' => $to->id]);

        return response()->json(['data' => $this->assetDetail($asset->fresh())]);
    }

    // Tickets

    public function tickets(Request $request): JsonResponse
    {
        $this->mayEnter();
        $status = $request->validate(['status' => ['nullable', Rule::in(['open', ...ItRepairTicket::STATUSES, 'all'])]])['status'] ?? 'open';
        $rows = $this->access->scope(ItRepairTicket::query())->with(['asset:id,asset_tag,asset_type,brand,model,workstation_no', 'lab:id,name', 'reporter:id,name', 'resolver:id,name'])
            ->when($status === 'open', fn ($q) => $q->where('status', '!=', 'resolved'))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->orderByRaw("case status when 'pending' then 0 when 'in_progress' then 1 else 2 end")->orderByDesc('id')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn (ItRepairTicket $t) => $this->ticket($t))->values(), 'meta' => ['can_resolve' => $this->access->canManage()]]);
    }

    public function startTicket(ItRepairTicket $ticket): JsonResponse
    {
        $this->book->start($ticket);
        AuditLogger::record('it', 'ticket_start', $ticket);

        return response()->json(['data' => $this->ticket($ticket->fresh(['asset', 'lab', 'reporter', 'resolver']))]);
    }

    public function resolveTicket(Request $request, ItRepairTicket $ticket): JsonResponse
    {
        $notes = $request->validate(['resolution_notes' => ['required', 'string', 'max:2000']])['resolution_notes'];
        $this->book->resolve($ticket, $notes, $request->user()->id);
        AuditLogger::record('it', 'ticket_resolve', $ticket);

        return response()->json(['data' => $this->ticket($ticket->fresh(['asset', 'lab', 'reporter', 'resolver']))]);
    }

    // For everyone

    /** An asset by its tag (printed on it), so anyone can report a problem. */
    public function lookup(Request $request): JsonResponse
    {
        $tag = $request->validate(['tag' => ['required', 'string', 'max:40']])['tag'];
        $asset = ItAsset::query()->where('asset_tag', strtoupper(trim($tag)))->with('lab:id,name')->first();
        abort_if($asset === null, 404, __('it.tag_not_found'));

        return response()->json(['data' => [
            'id' => $asset->id, 'asset_tag' => $asset->asset_tag, 'asset_type' => $asset->asset_type, 'brand' => $asset->brand, 'model' => $asset->model,
            'workstation_no' => $asset->workstation_no, 'lab' => $asset->lab?->name, 'status' => $asset->status,
            'open_tickets' => ItRepairTicket::query()->where('asset_id', $asset->id)->where('status', '!=', 'resolved')->count(),
        ]]);
    }

    public function report(Request $request, ItAsset $asset): JsonResponse
    {
        $description = $request->validate(['issue_description' => ['required', 'string', 'min:5', 'max:2000']])['issue_description'];
        $ticket = $this->book->report($asset, $description, $request->user());
        AuditLogger::record('it', 'ticket_report', $ticket, null, ['asset' => $asset->asset_tag]);

        return response()->json(['data' => $this->ticket($ticket->load(['asset', 'lab', 'reporter']))], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => ItRepairTicket::query()->where('reported_by', $request->user()->id)->with(['asset:id,asset_tag,asset_type,brand,model,workstation_no', 'lab:id,name', 'resolver:id,name'])
            ->orderByDesc('id')->limit(100)->get()->map(fn (ItRepairTicket $t) => $this->ticket($t))->values()]);
    }

    public function smartboards(): JsonResponse
    {
        $this->mayEnter();
        $rows = $this->access->scope(ItAsset::query())->where('asset_type', 'smartboard')->with(['lab:id,name'])
            ->withCount(['tickets', 'tickets as open_tickets' => fn ($q) => $q->where('status', '!=', 'resolved')])->orderBy('lab_id')->orderBy('asset_tag')->get();

        return response()->json(['data' => $rows->map(fn (ItAsset $a) => $this->asset($a) + ['tickets' => $a->tickets_count])->values()]);
    }

    public function importTemplate(): StreamedResponse
    {
        return Csv::download('it-asset-import-template.csv', ItAssetImport::COLUMNS, [
            ['Computer Lab 1', 'Ground floor', '40', 'PC-01', 'cpu', 'Dell', 'OptiPlex 3090', 'SN-CL1-C01', '2026-01-15', '35000', '2029-01-15', 'Windows 11 Pro', '2027-01-15'],
            ['Computer Lab 1', 'Ground floor', '40', '', 'smartboard', 'Promethean', 'ActivPanel', 'SN-CL1-SB1', '2026-02-01', '120000', '2031-02-01', '', ''],
        ]);
    }

    public function importPreview(Request $request, ItAssetImport $import): JsonResponse
    {
        $checked = $import->check($this->rows($request));

        return response()->json(['data' => ['rows' => $checked, 'total' => count($checked), 'with_errors' => collect($checked)->filter(fn ($r) => $r['errors'] !== [])->count()]]);
    }

    public function import(Request $request, ItAssetImport $import): JsonResponse
    {
        $checked = $import->check($this->rows($request));
        $bad = collect($checked)->filter(fn ($r) => $r['errors'] !== [])->values();
        if ($bad->isNotEmpty()) {
            return response()->json(['message' => __('import.has_errors'), 'errors' => ['file' => [__('import.has_errors')]], 'rows' => $bad], 422);
        }
        $done = $import->import($checked, $request->user());

        return response()->json(['data' => ['imported' => $done['assets'], 'labs_created' => $done['labs']]], 201);
    }

    // Helpers

    private function mayEnter(): void
    {
        abort_unless($this->access->canViewAll() || $this->access->isAssistant(), 403, __('auth.forbidden'));
    }

    private function visible(ItAsset $asset): ItAsset
    {
        $this->mayEnter();

        return $this->access->scope(ItAsset::query())->findOrFail($asset->id);
    }

    private function kept(ItAsset $asset): ItAsset
    {
        $asset = $this->visible($asset);
        abort_unless($this->access->canKeepLab($asset->lab_id), 403, __('auth.forbidden'));

        return $asset;
    }

    /** @return list<array<string, string>> */
    private function rows(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);

        return CsvReader::rows($request->file('file'), ItAssetImport::REQUIRED, 2000);
    }

    /** @return array<string, mixed> */
    private function labData(Request $request, ?ItLab $lab = null): array
    {
        $orgId = AccessContext::current()->organizationId();

        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('it_labs', 'name')->where('organization_id', $orgId)->ignore($lab?->id)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'location' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'assistant_user_id' => ['nullable', 'integer', Rule::exists('employees', 'user_id')->where('organization_id', $orgId)->where('is_active', true)],
        ]);
    }

    /** @return array<string, mixed> */
    private function assetData(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'lab_id' => [$creating ? 'required' : 'nullable', 'integer'],
            'asset_type' => [$creating ? 'required' : 'nullable', Rule::in(ItAsset::TYPES)],
            'workstation_no' => ['nullable', 'string', 'max:30'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'serial_no' => ['nullable', 'string', 'max:150'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'warranty_expiry' => ['nullable', 'date_format:Y-m-d'],
            'os_license' => ['nullable', 'string', 'max:150'],
            'license_expiry' => ['nullable', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** @return array<string, mixed> */
    private function lab(ItLab $l): array
    {
        return [
            'id' => $l->id, 'name' => $l->name, 'location' => $l->location, 'capacity' => $l->capacity, 'is_active' => $l->is_active,
            'department' => $l->relationLoaded('department') && $l->department ? ['id' => $l->department->id, 'name' => $l->department->name] : null,
            'assistant' => $l->relationLoaded('assistant') && $l->assistant ? ['id' => $l->assistant->id, 'name' => $l->assistant->name] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function asset(ItAsset $a): array
    {
        return [
            'id' => $a->id, 'asset_tag' => $a->asset_tag, 'asset_type' => $a->asset_type, 'workstation_no' => $a->workstation_no, 'brand' => $a->brand, 'model' => $a->model,
            'serial_no' => $a->serial_no, 'lab' => $a->lab ? ['id' => $a->lab->id, 'name' => $a->lab->name] : null, 'status' => $a->status, 'power_status' => $a->power_status,
            'warranty_expiry' => $a->warranty_expiry?->toDateString(), 'license_expiry' => $a->license_expiry?->toDateString(),
            'open_tickets' => (int) ($a->open_tickets ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function assetDetail(ItAsset $a): array
    {
        $a->load(['lab:id,name', 'tickets' => fn ($q) => $q->with(['reporter:id,name', 'resolver:id,name'])->orderByDesc('id'), 'transfers' => fn ($q) => $q->with(['from:id,name', 'to:id,name', 'mover:id,name'])->orderByDesc('id')]);
        $a->setAttribute('open_tickets', $a->tickets->where('status', '!=', 'resolved')->count());

        return $this->asset($a) + [
            'purchase_date' => $a->purchase_date?->toDateString(), 'purchase_cost' => $a->purchase_cost === null ? null : (float) $a->purchase_cost,
            'os_license' => $a->os_license, 'remarks' => $a->remarks, 'power_updated_at' => $a->power_updated_at?->toIso8601String(),
            'tickets' => $a->tickets->map(fn (ItRepairTicket $t) => $this->ticket($t))->values(),
            'transfers' => $a->transfers->map(fn (ItAssetTransfer $t) => ['from' => $t->from?->name, 'to' => $t->to?->name, 'remarks' => $t->remarks, 'by' => $t->mover?->name,
                'at' => $t->created_at?->toIso8601String()])->values(),
            'can_keep' => $this->access->canKeepLab($a->lab_id), 'can_manage' => $this->access->canManage(),
        ];
    }

    /** @return array<string, mixed> */
    private function ticket(ItRepairTicket $t): array
    {
        return [
            'id' => $t->id, 'status' => $t->status, 'issue_description' => $t->issue_description, 'resolution_notes' => $t->resolution_notes,
            'asset' => $t->relationLoaded('asset') && $t->asset ? ['id' => $t->asset->id, 'asset_tag' => $t->asset->asset_tag, 'asset_type' => $t->asset->asset_type,
                'label' => trim(($t->asset->brand ?? '').' '.($t->asset->model ?? '')) ?: null, 'workstation_no' => $t->asset->workstation_no] : null,
            'lab' => $t->relationLoaded('lab') ? $t->lab?->name : null,
            'reported_by' => $t->relationLoaded('reporter') ? $t->reporter?->name : null,
            'resolved_by' => $t->relationLoaded('resolver') ? $t->resolver?->name : null,
            'created_at' => $t->created_at?->toIso8601String(), 'resolved_at' => $t->resolved_at?->toIso8601String(),
        ];
    }

    private function today(): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
    }
}
