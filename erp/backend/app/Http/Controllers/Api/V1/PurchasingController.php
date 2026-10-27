<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FinancePayment;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuotation;
use App\Services\AttendanceRules;
use App\Services\Finance\BudgetLedger;
use App\Services\Purchasing\PurchaseDesk;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Quotations, purchase orders, goods received and vendor invoices of the
 * departments in the caller's purchasing scope (erp/docs/PURCHASING.md).
 */
class PurchasingController extends Controller
{
    public function __construct(private readonly PurchaseDesk $desk, private readonly BudgetLedger $budget) {}

    // Quotations

    public function quotations(Request $request): JsonResponse
    {
        $f = $request->validate(['department_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['received', 'accepted', 'rejected'])]]);
        $rows = $this->scoped(PurchaseQuotation::query(), 'purchasing.view')->with(['department:id,name', 'vendor:id,name', 'lines', 'order:id,quotation_id,po_number,status'])
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('quotation_date')->orderByDesc('id')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn (PurchaseQuotation $q) => $this->quotation($q))->values(), 'meta' => ['can_manage' => AccessContext::current()->can('purchasing.manage')]]);
    }

    public function storeQuotation(Request $request): JsonResponse
    {
        $data = $this->header($request, 'quotation_date') + $request->validate([
            'reference_no' => ['nullable', 'string', 'max:100'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:quotation_date'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $lines = $this->lines($request);
        $quotation = DB::transaction(function () use ($data, $lines, $request) {
            $q = PurchaseQuotation::create($data + ['status' => 'received', 'created_by' => $request->user()->id]);
            $q->forceFill(['total_amount' => $this->desk->setLines('purchase_quotation', $q->id, $lines)['total_amount']])->save();
            AuditLogger::record('purchasing', 'quotation_create', $q, null, ['total' => $q->total_amount]);

            return $q;
        });

        return response()->json(['data' => $this->quotation($quotation->load(['department:id,name', 'vendor:id,name', 'lines', 'order']))], 201);
    }

    /** accept (makes a draft order) or reject. */
    public function decideQuotation(Request $request, PurchaseQuotation $quotation): JsonResponse
    {
        $q = $this->managed(PurchaseQuotation::query(), $quotation->id);
        $decision = $request->validate(['decision' => ['required', Rule::in(['accept', 'reject'])]])['decision'];
        if ($decision === 'reject') {
            if ($q->status !== 'received') {
                throw ValidationException::withMessages(['quotation' => [__('purchasing.quotation_closed')]]);
            }
            $q->forceFill(['status' => 'rejected'])->save();
            AuditLogger::record('purchasing', 'quotation_reject', $q);

            return response()->json(['data' => $this->quotation($q->load(['department:id,name', 'vendor:id,name', 'lines', 'order']))]);
        }
        $order = $this->desk->orderFromQuotation($q->load('lines'), $this->today(), $request->user()->id);
        AuditLogger::record('purchasing', 'quotation_accept', $q, null, ['order' => $order->id]);

        return $this->order($order)->setStatusCode(201);
    }

    // Orders

    public function orders(Request $request): JsonResponse
    {
        $f = $request->validate([
            'department_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([...PurchaseOrder::STATUSES, 'open'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = $this->scoped(PurchaseOrder::query(), 'purchasing.view', true)->with(['department:id,name', 'vendor:id,name'])->withSum('invoices as invoiced_amount', 'total_amount')
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['vendor_id'] ?? null, fn ($q, $id) => $q->where('vendor_id', $id))
            ->when(($f['status'] ?? null) === 'open', fn ($q) => $q->whereIn('status', ['draft', 'issued', 'partly_received']))
            ->when(($f['status'] ?? 'open') !== 'open' && isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('po_number', 'like', $like)->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $like)));
            })
            ->orderByDesc('order_date')->orderByDesc('id')->paginate(25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (PurchaseOrder $o) => $this->orderRow($o))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(), 'can_manage' => AccessContext::current()->can('purchasing.manage')],
        ]);
    }

    public function showOrder(PurchaseOrder $order): JsonResponse
    {
        return $this->order($this->scoped(PurchaseOrder::query(), 'purchasing.view', true)->findOrFail($order->id));
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $data = $this->header($request, 'order_date') + $this->orderExtras($request);
        $lines = $this->lines($request);
        $order = DB::transaction(function () use ($data, $lines, $request) {
            $o = PurchaseOrder::create($data + ['status' => 'draft', 'created_by' => $request->user()->id]);
            $o->forceFill($this->desk->setLines('purchase_order', $o->id, $lines))->save();
            AuditLogger::record('purchasing', 'order_create', $o, null, ['total' => $o->total_amount]);

            return $o;
        });

        return $this->order($order)->setStatusCode(201);
    }

    /** Only a draft order can be changed. */
    public function updateOrder(Request $request, PurchaseOrder $order): JsonResponse
    {
        $o = $this->managed(PurchaseOrder::query(), $order->id);
        if ($o->status !== 'draft') {
            throw ValidationException::withMessages(['status' => [__('purchasing.not_draft')]]);
        }
        $data = $this->header($request, 'order_date') + $this->orderExtras($request);
        $lines = $this->lines($request);
        DB::transaction(function () use ($o, $data, $lines) {
            $before = $o->getAttributes();
            $o->fill($data)->save();
            $o->forceFill($this->desk->setLines('purchase_order', $o->id, $lines))->save();
            AuditLogger::recordChanges('purchasing', 'order_update', $o, $before);
        });

        return $this->order($o);
    }

    public function issueOrder(Request $request, PurchaseOrder $order): JsonResponse
    {
        $o = $this->desk->issue($this->managed(PurchaseOrder::query(), $order->id), $request->user()->id);
        AuditLogger::record('purchasing', 'order_issue', $o, null, ['po_number' => $o->po_number]);

        return $this->order($o);
    }

    /** A draft or issued order with nothing received can be cancelled. */
    public function cancelOrder(Request $request, PurchaseOrder $order): JsonResponse
    {
        $o = $this->managed(PurchaseOrder::query(), $order->id);
        if (! in_array($o->status, ['draft', 'issued'], true)) {
            throw ValidationException::withMessages(['status' => [__('purchasing.cannot_cancel')]]);
        }
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $o->forceFill(['status' => 'cancelled'])->save();
        AuditLogger::record('purchasing', 'order_cancel', $o, null, ['reason' => $reason]);

        return $this->order($o);
    }

    /** Goods received: purchasing.manage over the department, or the store (store.manage). */
    public function receive(Request $request, PurchaseOrder $order): JsonResponse
    {
        $o = $this->scoped(PurchaseOrder::query(), 'purchasing.view', true)->findOrFail($order->id);
        $canReceive = AccessContext::current()->can('store.manage') || ScopeDepartments::reaches(AccessContext::current()->scope('purchasing.manage'), $o->department_id);
        abort_unless($canReceive, 403, __('auth.forbidden'));
        $data = $request->validate([
            'received_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $quantities = collect($data['lines'])->mapWithKeys(fn ($l) => [(int) $l['line_id'] => (float) $l['quantity']])->all();
        $receipt = $this->desk->receive($o, $quantities, $data['received_date'], $data['remarks'] ?? null, $request->user()->id);
        AuditLogger::record('purchasing', 'goods_received', $o, null, ['grn' => $receipt->grn_number]);

        return $this->order($o);
    }

    public function storeInvoice(Request $request, PurchaseOrder $order): JsonResponse
    {
        $o = $this->managed(PurchaseOrder::query(), $order->id);
        $data = $request->validate([
            'invoice_number' => ['required', 'string', 'max:100', Rule::unique('purchase_invoices', 'invoice_number')->where('vendor_id', $o->vendor_id)],
            'invoice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today(), 'after_or_equal:'.$o->order_date->toDateString()],
            'goods_amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'other_charges' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $invoice = $this->desk->invoice($o, $data, $request->user()->id);
        AuditLogger::record('purchasing', 'invoice_create', $o, null, ['invoice' => $invoice->id, 'total' => $invoice->total_amount]);

        return $this->order($o);
    }

    /** An invoice with no payments can be removed; its budget charge goes with it. */
    public function destroyInvoice(PurchaseInvoice $invoice): JsonResponse
    {
        $o = $this->managed(PurchaseOrder::query(), $invoice->purchase_order_id);
        if ($invoice->payments()->exists()) {
            throw ValidationException::withMessages(['invoice' => [__('finance.has_payments')]]);
        }
        DB::transaction(function () use ($invoice, $o) {
            AuditLogger::record('purchasing', 'invoice_delete', $o, AuditLogger::snapshot($invoice));
            $this->budget->reverse('purchase_invoice', $invoice->id);
            $invoice->delete();
        });

        return $this->order($o);
    }

    public function payInvoice(Request $request, PurchaseInvoice $invoice): JsonResponse
    {
        $o = $this->managed(PurchaseOrder::query(), $invoice->purchase_order_id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'payment_type' => ['required', Rule::in(FinancePayment::TYPES)],
            'payment_mode' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($invoice, $data, $request) {
            $locked = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $due = round((float) $locked->total_amount - (float) $locked->payments()->sum('amount'), 2);
            if ((float) $data['amount'] > $due + 0.001) {
                throw ValidationException::withMessages(['amount' => [__('finance.more_than_due', ['due' => number_format($due, 2)])]]);
            }
            $payment = FinancePayment::create($data + [
                'department_id' => $locked->department_id, 'source_type' => 'purchase_invoice', 'source_id' => $locked->id, 'recorded_by' => $request->user()->id,
            ]);
            AuditLogger::record('purchasing', 'invoice_payment', $locked, null, ['payment' => $payment->id, 'amount' => $payment->amount]);
        });

        return $this->order($o);
    }

    // Helpers

    private function order(PurchaseOrder $o): JsonResponse
    {
        $o->load(['department:id,name', 'vendor', 'quotation:id,reference_no,quotation_date', 'lines.item:id,item_name,unit', 'creator:id,name',
            'receipts.lines', 'receipts.receiver:id,name', 'invoices.payments.recorder:id,name']);
        $lineNames = $o->lines->keyBy('id');
        $received = $this->desk->receivedValue($o);
        $invoicedGoods = round((float) $o->invoices->sum('goods_amount'), 2);
        $manage = ScopeDepartments::reaches(AccessContext::current()->scope('purchasing.manage'), $o->department_id);

        return response()->json(['data' => $this->orderRow($o) + [
            'vendor' => VendorController::present($o->vendor),
            'quotation' => $o->quotation ? ['id' => $o->quotation->id, 'reference_no' => $o->quotation->reference_no, 'quotation_date' => $o->quotation->quotation_date->toDateString()] : null,
            'expected_date' => $o->expected_date?->toDateString(),
            'terms' => $o->terms,
            'subtotal' => (float) $o->subtotal,
            'gst_amount' => (float) $o->gst_amount,
            'created_by' => $o->creator?->name,
            'issued_at' => $o->issued_at?->toIso8601String(),
            'lines' => $o->lines->map(fn (PurchaseLine $l) => $this->line($l))->values(),
            'receipts' => $o->receipts->map(fn (GoodsReceipt $r) => [
                'id' => $r->id, 'grn_number' => $r->grn_number, 'received_date' => $r->received_date->toDateString(), 'remarks' => $r->remarks, 'received_by' => $r->receiver?->name,
                'lines' => $r->lines->map(fn ($rl) => ['description' => $lineNames->get($rl->purchase_line_id)?->description, 'quantity' => (float) $rl->quantity])->values(),
            ])->values(),
            'invoices' => $o->invoices->map(function (PurchaseInvoice $i) {
                $paid = round((float) $i->payments->sum('amount'), 2);

                return [
                    'id' => $i->id, 'invoice_number' => $i->invoice_number, 'invoice_date' => $i->invoice_date->toDateString(), 'goods_amount' => (float) $i->goods_amount,
                    'other_charges' => (float) $i->other_charges, 'total_amount' => (float) $i->total_amount, 'paid_amount' => $paid, 'due_amount' => round((float) $i->total_amount - $paid, 2),
                    'remarks' => $i->remarks,
                    'payments' => $i->payments->map(fn (FinancePayment $p) => ['id' => $p->id, 'amount' => (float) $p->amount, 'payment_date' => $p->payment_date->toDateString(),
                        'payment_type' => $p->payment_type, 'payment_mode' => $p->payment_mode, 'recorded_by' => $p->recorder?->name])->values(),
                ];
            })->values(),
            'received_value' => $received,
            'invoiceable_amount' => max(round($received - $invoicedGoods, 2), 0),
            'can_manage' => $manage,
            'can_receive' => in_array($o->status, ['issued', 'partly_received'], true) && ($manage || AccessContext::current()->can('store.manage')),
            'budget_left' => $this->budget->remaining($o->department_id, $o->order_date->toDateString()),
        ]]);
    }

    /** @return array<string, mixed> */
    private function orderRow(PurchaseOrder $o): array
    {
        return [
            'id' => $o->id, 'po_number' => $o->po_number, 'status' => $o->status, 'order_date' => $o->order_date->toDateString(),
            'department' => $o->department ? ['id' => $o->department->id, 'name' => $o->department->name] : null,
            'vendor_name' => $o->vendor?->name, 'total_amount' => (float) $o->total_amount,
            'invoiced_amount' => round((float) ($o->invoiced_amount ?? $o->invoices?->sum('total_amount') ?? 0), 2),
        ];
    }

    /** @return array<string, mixed> */
    private function quotation(PurchaseQuotation $q): array
    {
        return [
            'id' => $q->id, 'reference_no' => $q->reference_no, 'status' => $q->status, 'quotation_date' => $q->quotation_date->toDateString(),
            'valid_until' => $q->valid_until?->toDateString(), 'department' => $q->department ? ['id' => $q->department->id, 'name' => $q->department->name] : null,
            'vendor' => $q->vendor ? ['id' => $q->vendor->id, 'name' => $q->vendor->name] : null, 'total_amount' => (float) $q->total_amount, 'remarks' => $q->remarks,
            'lines' => $q->lines->map(fn (PurchaseLine $l) => $this->line($l))->values(),
            'order' => $q->order ? ['id' => $q->order->id, 'po_number' => $q->order->po_number, 'status' => $q->order->status] : null,
            'can_manage' => ScopeDepartments::reaches(AccessContext::current()->scope('purchasing.manage'), $q->department_id),
        ];
    }

    /** @return array<string, mixed> */
    private function line(PurchaseLine $l): array
    {
        return [
            'id' => $l->id, 'item_id' => $l->item_id, 'description' => $l->description, 'quantity' => (float) $l->quantity, 'unit' => $l->unit,
            'rate' => (float) $l->rate, 'gst_percent' => (float) $l->gst_percent, 'amount' => (float) $l->amount, 'received_quantity' => (float) $l->received_quantity,
        ];
    }

    /** @return array<string, mixed> department and vendor (within the caller's manage scope) and the date field */
    private function header(Request $request, string $dateField): array
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->where('organization_id', $orgId)->where('is_active', true)],
            $dateField => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
        ]);
        if (! ScopeDepartments::reaches(AccessContext::current()->scope('purchasing.manage'), (int) $data['department_id'])) {
            throw ValidationException::withMessages(['department_id' => [__('access.outside_scope')]]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function orderExtras(Request $request): array
    {
        return $request->validate([
            'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function lines(Request $request): array
    {
        return $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'required_without:lines.*.item_id', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'lines.*.unit' => ['nullable', 'string', 'max:30'],
            'lines.*.rate' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.gst_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ])['lines'];
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function scoped(Builder $query, string $permission, bool $orStore = false): Builder
    {
        if ($orStore && AccessContext::current()->can('store.manage')) {
            return $query;
        }
        $ids = ScopeDepartments::ids(AccessContext::current()->scope($permission));

        return $query->when($ids !== null, fn ($q) => $q->whereIn('department_id', $ids ?: [0]));
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return T
     */
    private function managed(Builder $query, int $id): Model
    {
        return $this->scoped($query, 'purchasing.manage')->findOrFail($id);
    }

    private function today(): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
    }
}
