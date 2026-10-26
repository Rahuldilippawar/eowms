<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\FinancePayment;
use App\Models\RenewalAttachment;
use App\Models\RenewalHistory;
use App\Models\RenewalItem;
use App\Services\AttendanceRules;
use App\Services\Finance\BudgetLedger;
use App\Services\RenewalImport;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Renewals (erp/docs/RENEWALS.md): items with an expiry date, renewing them
 * (the old cycle goes to the history), payments per cycle or per month
 * (charged to the department budget), documents, the calendar and cost view.
 */
class RenewalController extends Controller
{
    public function __construct(private readonly BudgetLedger $ledger) {}

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['expired', 'due_soon', 'active', 'inactive'])],
            'department_id' => ['nullable', 'integer'],
            'regulatory_body' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $today = $this->today();
        $soon = $today->addDays(RenewalItem::DUE_SOON_DAYS)->toDateString();
        $base = $this->scoped('renewals.view')
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['regulatory_body'] ?? null, fn ($q, $b) => $q->where('regulatory_body', $b))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('item_name', 'like', $like)->orWhere('item_type', 'like', $like)
                    ->orWhere('reference_no', 'like', $like)->orWhere('vendor_name', 'like', $like));
            });
        $active = (clone $base)->where('is_active', true);
        $summary = [
            'expired' => (clone $active)->where('expiry_date', '<', $today->toDateString())->count(),
            'due_soon' => (clone $active)->whereBetween('expiry_date', [$today->toDateString(), $soon])->count(),
            'active' => (clone $active)->where('expiry_date', '>', $soon)->count(),
        ];
        $byBody = (clone $active)->whereNotNull('regulatory_body')
            ->selectRaw('regulatory_body, SUM(expiry_date < ?) as expired, SUM(expiry_date BETWEEN ? AND ?) as due_soon, COUNT(*) as total', [$today->toDateString(), $today->toDateString(), $soon])
            ->groupBy('regulatory_body')->orderByDesc('expired')->orderByDesc('due_soon')->get()
            ->map(fn ($r) => ['regulatory_body' => $r->regulatory_body, 'expired' => (int) $r->expired, 'due_soon' => (int) $r->due_soon, 'total' => (int) $r->total]);

        $query = match ($f['status'] ?? null) {
            'expired' => $active->where('expiry_date', '<', $today->toDateString()),
            'due_soon' => $active->whereBetween('expiry_date', [$today->toDateString(), $soon]),
            'active' => $active->where('expiry_date', '>', $soon),
            'inactive' => $base->where('is_active', false),
            default => $active,
        };
        $query->with(['department:id,name,code', 'responsible:id,name'])->orderBy('expiry_date')->orderBy('id');

        if (($f['format'] ?? null) === 'csv') {
            abort_unless(AccessContext::current()->can('renewals.export'), 403, __('auth.forbidden'));
            $rows = $query->limit(20000)->get();
            AuditLogger::record('renewals', 'export', null, null, ['rows' => $rows->count()]);

            return Csv::download('renewals.csv',
                ['department', 'item_type', 'regulatory_body', 'item_name', 'reference_no', 'issued_date', 'expiry_date', 'days_left', 'status', 'renewal_cost', 'vendor_name', 'responsible'],
                $rows->map(function (RenewalItem $r) use ($today) {
                    $days = $r->daysLeft($today);

                    return [$r->department?->name, $r->item_type, $r->regulatory_body, $r->item_name, $r->reference_no, $r->issued_date?->toDateString(),
                        $r->expiry_date->toDateString(), $days, RenewalItem::statusFor($days), $r->renewal_cost, $r->vendor_name, $r->responsible?->name];
                }));
        }
        $page = $query->paginate(25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (RenewalItem $r) => $this->present($r, $today))->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(), 'summary' => $summary, 'by_regulatory_body' => $byBody,
                'can_manage' => AccessContext::current()->can('renewals.manage'), 'regulatory_bodies' => RenewalItem::REGULATORY_BODIES,
            ],
        ]);
    }

    public function show(RenewalItem $renewal): JsonResponse
    {
        $item = $this->scoped('renewals.view')->with(['department:id,name,code', 'responsible:id,name', 'history.renewer:id,name', 'attachments', 'payments.recorder:id,name'])
            ->findOrFail($renewal->id);
        $today = $this->today();
        $paidMonths = $item->payments->whereNotNull('for_month')->map(fn ($p) => $p->for_month->format('Y-m'))->unique()->values();

        return response()->json(['data' => $this->present($item, $today) + [
            'vendor_contact' => $item->vendor_contact,
            'recurring_start_month' => $item->recurring_start_month?->format('Y-m'),
            'can_manage' => ScopeDepartments::reaches(AccessContext::current()->scope('renewals.manage'), $item->department_id),
            'months' => $item->is_recurring_monthly && $item->recurring_start_month ? $this->months($item->recurring_start_month->format('Y-m'), $today, $paidMonths->all()) : [],
            'history' => $item->history->map(fn (RenewalHistory $h) => [
                'old_expiry_date' => $h->old_expiry_date->toDateString(), 'old_issued_date' => $h->old_issued_date?->toDateString(),
                'old_renewal_cost' => $h->old_renewal_cost === null ? null : (float) $h->old_renewal_cost, 'old_vendor_name' => $h->old_vendor_name,
                'old_reference_no' => $h->old_reference_no, 'new_expiry_date' => $h->new_expiry_date->toDateString(), 'renewed_by' => $h->renewer?->name,
                'created_at' => $h->created_at?->toIso8601String(),
            ])->values(),
            'payments' => $item->payments->map(fn (FinancePayment $p) => [
                'id' => $p->id, 'amount' => (float) $p->amount, 'payment_date' => $p->payment_date->toDateString(), 'payment_type' => $p->payment_type,
                'payment_mode' => $p->payment_mode, 'for_month' => $p->for_month?->format('Y-m'), 'cycle_expiry_date' => $p->cycle_expiry_date?->toDateString(),
                'remarks' => $p->remarks, 'recorded_by' => $p->recorder?->name,
            ])->values(),
            'attachments' => $item->attachments->map(fn (RenewalAttachment $a) => [
                'id' => $a->id, 'title' => $a->title, 'name' => $a->original_name, 'size' => $a->size, 'cycle_expiry_date' => $a->cycle_expiry_date?->toDateString(),
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $item = RenewalItem::create($this->validated($request) + ['created_by' => $request->user()->id]);
        AuditLogger::record('renewals', 'create', $item, null, AuditLogger::snapshot($item));

        return $this->show($item)->setStatusCode(201);
    }

    /** Edits the current cycle's details; renewing (a new expiry) is its own step so the history stays right. */
    public function update(Request $request, RenewalItem $renewal): JsonResponse
    {
        $item = $this->managed($renewal);
        $before = $item->getAttributes();
        $item->fill($this->validated($request, $item))->save();
        AuditLogger::recordChanges('renewals', 'update', $item, $before);

        return $this->show($item);
    }

    public function setActive(Request $request, RenewalItem $renewal): JsonResponse
    {
        $item = $this->managed($renewal);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $before = $item->getAttributes();
        $item->forceFill(['is_active' => $data['is_active']])->save();
        AuditLogger::recordChanges('renewals', $data['is_active'] ? 'activate' : 'deactivate', $item, $before);

        return $this->show($item);
    }

    public function renew(Request $request, RenewalItem $renewal): JsonResponse
    {
        $item = $this->managed($renewal);
        $data = $request->validate([
            'new_expiry_date' => ['required', 'date_format:Y-m-d', 'after:'.$item->expiry_date->toDateString()],
            'issued_date' => ['nullable', 'date_format:Y-m-d'],
            'renewal_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'reference_no' => ['nullable', 'string', 'max:100'],
        ]);
        DB::transaction(function () use ($item, $data, $request) {
            $locked = RenewalItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            RenewalHistory::create([
                'renewal_item_id' => $locked->id, 'old_expiry_date' => $locked->expiry_date, 'old_issued_date' => $locked->issued_date,
                'old_renewal_cost' => $locked->renewal_cost, 'old_vendor_name' => $locked->vendor_name, 'old_reference_no' => $locked->reference_no,
                'new_expiry_date' => $data['new_expiry_date'], 'renewed_by' => $request->user()->id,
            ]);
            $before = $locked->getAttributes();
            $locked->forceFill([
                'expiry_date' => $data['new_expiry_date'],
                'issued_date' => $data['issued_date'] ?? $this->today()->toDateString(),
                'renewal_cost' => array_key_exists('renewal_cost', $data) ? $data['renewal_cost'] : $locked->renewal_cost,
                'vendor_name' => ($data['vendor_name'] ?? null) ?: $locked->vendor_name,
                'reference_no' => ($data['reference_no'] ?? null) ?: $locked->reference_no,
            ])->save();
            AuditLogger::recordChanges('renewals', 'renew', $locked, $before);
        });

        return $this->show($item);
    }

    /** A payment for the current cycle (or a month of a monthly bill); charged to the department budget. */
    public function pay(Request $request, RenewalItem $renewal): JsonResponse
    {
        $item = $this->managed($renewal);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()->toDateString()],
            'payment_type' => ['required', Rule::in(FinancePayment::TYPES)],
            'payment_mode' => ['nullable', 'string', 'max:50'],
            'for_month' => [$item->is_recurring_monthly ? 'required' : 'nullable', 'date_format:Y-m'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($item, $data, $request) {
            $payment = FinancePayment::create([
                'department_id' => $item->department_id, 'source_type' => 'renewal', 'source_id' => $item->id, 'amount' => $data['amount'],
                'payment_date' => $data['payment_date'], 'payment_type' => $data['payment_type'], 'payment_mode' => $data['payment_mode'] ?? null,
                'for_month' => isset($data['for_month']) ? $data['for_month'].'-01' : null, 'cycle_expiry_date' => $item->expiry_date,
                'remarks' => $data['remarks'] ?? null, 'recorded_by' => $request->user()->id,
            ]);
            $this->ledger->record(Department::query()->findOrFail($item->department_id), (float) $payment->amount, 'renewal', 'renewal_payment', $payment->id,
                "{$item->item_name} ({$item->item_type})", $payment->payment_date->toDateString(), $request->user()->id);
            AuditLogger::record('renewals', 'payment', $item, null, ['payment' => $payment->id, 'amount' => $payment->amount]);
        });

        return $this->show($item);
    }

    public function attach(Request $request, RenewalItem $renewal): JsonResponse
    {
        $item = $this->managed($renewal);
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'], 'title' => ['nullable', 'string', 'max:255']]);
        $file = $request->file('file');
        $attachment = RenewalAttachment::create([
            'renewal_item_id' => $item->id, 'cycle_expiry_date' => $item->expiry_date, 'title' => $request->input('title'),
            'path' => $file->storeAs("renewals/{$item->organization_id}/{$item->id}", Str::uuid().'.'.$file->guessExtension(), 'local'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(), 'uploaded_by' => $request->user()->id,
        ]);
        AuditLogger::record('renewals', 'attach', $item, null, ['file' => $attachment->original_name]);

        return $this->show($item);
    }

    public function attachment(RenewalItem $renewal, RenewalAttachment $attachment): StreamedResponse
    {
        $item = $this->scoped('renewals.view')->findOrFail($renewal->id);
        abort_unless($attachment->renewal_item_id === $item->id && Storage::disk('local')->exists($attachment->path), 404);
        AuditLogger::record('renewals', 'attachment_download', $item, null, ['file' => $attachment->original_name]);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Items expiring in a month, by day. */
    public function calendar(Request $request): JsonResponse
    {
        $f = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $today = $this->today();
        $month = CarbonImmutable::parse(($f['month'] ?? $today->format('Y-m')).'-01');
        $items = $this->scoped('renewals.view')->where('is_active', true)->with('department:id,name')
            ->whereBetween('expiry_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])->orderBy('expiry_date')->get();

        return response()->json(['data' => $items->map(fn (RenewalItem $r) => $this->present($r, $today))->values(), 'meta' => ['month' => $month->format('Y-m')]]);
    }

    /** Cost by year and item type: past cycles (history) and current ones, plus what was actually paid. */
    public function costs(Request $request): JsonResponse
    {
        $ids = $this->scoped('renewals.view')->pluck('id');
        $planned = collect()
            ->concat(RenewalItem::query()->whereIn('id', $ids)->whereNotNull('renewal_cost')
                ->selectRaw('YEAR(expiry_date) as yr, item_type, SUM(renewal_cost) as total, COUNT(*) as n')->groupBy('yr', 'item_type')->get())
            ->concat(RenewalHistory::query()->join('renewal_items', 'renewal_items.id', '=', 'renewal_history.renewal_item_id')
                ->whereIn('renewal_items.id', $ids)->whereNotNull('old_renewal_cost')
                ->selectRaw('YEAR(old_expiry_date) as yr, renewal_items.item_type, SUM(old_renewal_cost) as total, COUNT(*) as n')->groupBy('yr', 'renewal_items.item_type')->get())
            ->groupBy(fn ($r) => $r->yr.'|'.$r->item_type)
            ->map(fn ($g) => ['year' => (int) $g->first()->yr, 'item_type' => $g->first()->item_type, 'total' => round((float) $g->sum('total'), 2), 'count' => (int) $g->sum('n')])
            ->sortBy([['year', 'desc'], ['total', 'desc']])->values();
        $paid = FinancePayment::query()->where('source_type', 'renewal')->whereIn('source_id', $ids)
            ->selectRaw('YEAR(payment_date) as yr, SUM(amount) as total')->groupBy('yr')->orderByDesc('yr')->get()
            ->map(fn ($r) => ['year' => (int) $r->yr, 'total' => round((float) $r->total, 2)]);

        return response()->json(['data' => ['by_year_type' => $planned, 'paid_by_year' => $paid]]);
    }

    public function importTemplate(): StreamedResponse
    {
        return Csv::download('renewals-template.csv', RenewalImport::COLUMNS, [
            ['IT', 'Software License', '', 'Windows 11 Pro License', 'LIC-2026-001', '2026-01-01', '2027-01-01', '5000', 'Microsoft Partner', '9876543210', ''],
            ['PHY', 'College Affiliation', 'AICTE', 'AICTE Approval 2026-27', 'AICTE-2026-XYZ', '2026-06-01', '2027-06-01', '25000', '', '', 'EMP-002'],
        ]);
    }

    public function importPreview(Request $request, RenewalImport $import): JsonResponse
    {
        $checked = $import->check($this->importRows($request));

        return response()->json(['data' => ['rows' => $checked, 'total' => count($checked), 'with_errors' => collect($checked)->filter(fn ($r) => $r['errors'] !== [])->count()]]);
    }

    public function import(Request $request, RenewalImport $import): JsonResponse
    {
        $checked = $import->check($this->importRows($request));
        $bad = collect($checked)->filter(fn ($r) => $r['errors'] !== [])->values();
        if ($bad->isNotEmpty()) {
            return response()->json(['message' => __('import.has_errors'), 'errors' => ['file' => [__('import.has_errors')]], 'rows' => $bad], 422);
        }

        return response()->json(['data' => ['imported' => $import->import($checked, $request->user())]], 201);
    }

    /** @return list<array<string, string>> */
    private function importRows(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);

        return CsvReader::rows($request->file('file'), RenewalImport::REQUIRED, 2000);
    }

    /**
     * @param  list<string>  $paid
     * @return list<array{month: string, paid: bool}>
     */
    private function months(string $start, CarbonImmutable $today, array $paid): array
    {
        $out = [];
        for ($m = CarbonImmutable::parse($start.'-01'); $m->format('Y-m') <= $today->format('Y-m') && count($out) < 120; $m = $m->addMonth()) {
            $out[] = ['month' => $m->format('Y-m'), 'paid' => in_array($m->format('Y-m'), $paid, true)];
        }

        return $out;
    }

    /** @return Builder<RenewalItem> */
    private function scoped(string $permission): Builder
    {
        $ids = ScopeDepartments::ids(AccessContext::current()->scope($permission));

        return RenewalItem::query()->when($ids !== null, fn ($q) => $q->whereIn('department_id', $ids ?: [0]));
    }

    private function managed(RenewalItem $item): RenewalItem
    {
        return $this->scoped('renewals.manage')->findOrFail($item->id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?RenewalItem $item = null): array
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'item_type' => ['required', 'string', 'max:100'],
            'regulatory_body' => ['nullable', Rule::in(RenewalItem::REGULATORY_BODIES)],
            'item_name' => ['required', 'string', 'max:255'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'issued_date' => ['nullable', 'date_format:Y-m-d'],
            'expiry_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issued_date'],
            'renewal_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'is_recurring_monthly' => ['sometimes', 'boolean'],
            'monthly_amount' => ['nullable', 'required_if:is_recurring_monthly,true', 'numeric', 'gt:0', 'max:9999999999'],
            'recurring_start_month' => ['nullable', 'required_if:is_recurring_monthly,true', 'date_format:Y-m'],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'vendor_contact' => ['nullable', 'string', 'max:100'],
            'responsible_user_id' => ['nullable', 'integer'],
        ]);
        if (! ScopeDepartments::reaches(AccessContext::current()->scope('renewals.manage'), (int) $data['department_id'])) {
            throw ValidationException::withMessages(['department_id' => [__('access.outside_scope')]]);
        }
        if (! empty($data['responsible_user_id']) && ! Employee::query()->where('user_id', $data['responsible_user_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['responsible_user_id' => [__('letters.choose_recipient')]]);
        }
        $monthly = (bool) ($data['is_recurring_monthly'] ?? false);

        return [
            ...$data,
            'institution_id' => Department::query()->whereKey($data['department_id'])->value('institution_id'),
            'is_recurring_monthly' => $monthly,
            'monthly_amount' => $monthly ? $data['monthly_amount'] : null,
            'recurring_start_month' => $monthly ? $data['recurring_start_month'].'-01' : null,
        ];
    }

    /** @return array<string, mixed> */
    private function present(RenewalItem $r, CarbonImmutable $today): array
    {
        $days = $r->daysLeft($today);

        return [
            'id' => $r->id,
            'department' => $r->department ? ['id' => $r->department->id, 'name' => $r->department->name] : null,
            'responsible' => $r->responsible ? ['id' => $r->responsible->id, 'name' => $r->responsible->name] : null,
            'item_type' => $r->item_type,
            'regulatory_body' => $r->regulatory_body,
            'item_name' => $r->item_name,
            'reference_no' => $r->reference_no,
            'issued_date' => $r->issued_date?->toDateString(),
            'expiry_date' => $r->expiry_date->toDateString(),
            'days_left' => $days,
            'status' => $r->is_active ? RenewalItem::statusFor($days) : 'inactive',
            'renewal_cost' => $r->renewal_cost === null ? null : (float) $r->renewal_cost,
            'is_recurring_monthly' => $r->is_recurring_monthly,
            'monthly_amount' => $r->monthly_amount === null ? null : (float) $r->monthly_amount,
            'vendor_name' => $r->vendor_name,
            'is_active' => $r->is_active,
            'cycle_paid' => round((float) FinancePayment::query()->where('source_type', 'renewal')->where('source_id', $r->id)
                ->where('cycle_expiry_date', $r->expiry_date->toDateString())->sum('amount'), 2),
        ];
    }

    private function today(): CarbonImmutable
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->startOfDay();
    }
}
