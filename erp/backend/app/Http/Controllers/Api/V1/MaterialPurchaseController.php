<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\FinancePayment;
use App\Models\MaterialPurchase;
use App\Services\AttendanceRules;
use App\Services\Finance\BudgetLedger;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Material purchases of a department and the payments against them. The
 * purchase total is charged to the department budget once, in the financial
 * year of the purchase date; payments only track what has been paid.
 */
class MaterialPurchaseController extends Controller
{
    public function __construct(private readonly BudgetLedger $ledger) {}

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'fy' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['pending', 'partial', 'paid'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $fy = (int) ($f['fy'] ?? BudgetLedger::fyOf($this->today()));
        $query = $this->scoped('finance.view')->with(['department:id,name,code'])->withSum('payments as paid_amount', 'amount')
            ->whereBetween('purchase_date', [$fy.'-04-01', ($fy + 1).'-03-31'])
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('material_name', 'like', $like)->orWhere('vendor_name', 'like', $like));
            })
            ->when($f['status'] ?? null, function ($q, $s) {
                $paid = "(select coalesce(sum(fp.amount), 0) from finance_payments fp where fp.source_type = 'material_purchase' and fp.source_id = material_purchases.id)";
                match ($s) {
                    'pending' => $q->whereRaw("{$paid} = 0"),
                    'partial' => $q->whereRaw("{$paid} > 0 and {$paid} < material_purchases.total_amount"),
                    'paid' => $q->whereRaw("{$paid} >= material_purchases.total_amount"),
                };
            })
            ->orderByDesc('purchase_date')->orderByDesc('id');

        if (($f['format'] ?? null) === 'csv') {
            abort_unless(AccessContext::current()->can('finance.export'), 403, __('auth.forbidden'));
            $rows = $query->limit(20000)->get();
            AuditLogger::record('finance', 'purchases_export', null, null, ['fy' => $fy, 'rows' => $rows->count()]);

            return Csv::download(sprintf('material-purchases-%d-%02d.csv', $fy, ($fy + 1) % 100),
                ['date', 'department', 'material', 'vendor', 'quantity', 'unit', 'rate', 'base', 'transport', 'gst_percent', 'gst', 'total', 'paid', 'due'],
                $rows->map(fn (MaterialPurchase $p) => [
                    $p->purchase_date->toDateString(), $p->department?->name, $p->material_name, $p->vendor_name, $p->quantity, $p->unit, $p->rate,
                    $p->base_amount, $p->transport_cost, $p->gst_percent, $p->gst_amount, $p->total_amount, round((float) $p->paid_amount, 2),
                    round((float) $p->total_amount - (float) $p->paid_amount, 2),
                ]));
        }
        $page = $query->paginate(25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (MaterialPurchase $p) => $this->present($p))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(), 'fy' => $fy, 'current_fy' => BudgetLedger::fyOf($this->today()), 'can_manage' => AccessContext::current()->can('finance.manage')],
        ]);
    }

    public function show(MaterialPurchase $purchase): JsonResponse
    {
        $purchase = $this->scoped('finance.view')->withSum('payments as paid_amount', 'amount')->findOrFail($purchase->id);

        return response()->json(['data' => $this->present($purchase->load(['department:id,name,code', 'payments.recorder:id,name', 'creator:id,name']), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $purchase = DB::transaction(function () use ($data, $request) {
            $purchase = MaterialPurchase::create($data + ['created_by' => $request->user()->id]);
            $this->charge($purchase, $request->user()->id);
            AuditLogger::record('finance', 'purchase_create', $purchase, null, AuditLogger::snapshot($purchase));

            return $purchase;
        });

        return $this->show($purchase)->setStatusCode(201);
    }

    public function update(Request $request, MaterialPurchase $purchase): JsonResponse
    {
        $purchase = $this->managed($purchase);
        $data = $this->validated($request);
        $paid = (float) $purchase->payments()->sum('amount');
        if ($paid > (float) $data['total_amount'] + 0.001) {
            throw ValidationException::withMessages(['rate' => [__('finance.total_below_paid', ['paid' => number_format($paid, 2)])]]);
        }
        DB::transaction(function () use ($purchase, $data, $request) {
            $before = $purchase->getAttributes();
            $purchase->fill($data)->save();
            $this->charge($purchase, $request->user()->id);
            FinancePayment::query()->where('source_type', 'material_purchase')->where('source_id', $purchase->id)->update(['department_id' => $purchase->department_id]);
            AuditLogger::recordChanges('finance', 'purchase_update', $purchase, $before);
        });

        return $this->show($purchase);
    }

    /** Only a purchase with no payments can be removed; its budget charge goes with it. */
    public function destroy(MaterialPurchase $purchase): JsonResponse
    {
        $purchase = $this->managed($purchase);
        if ($purchase->payments()->exists()) {
            throw ValidationException::withMessages(['purchase' => [__('finance.has_payments')]]);
        }
        DB::transaction(function () use ($purchase) {
            AuditLogger::record('finance', 'purchase_delete', $purchase, AuditLogger::snapshot($purchase));
            $this->ledger->reverse('material_purchase', $purchase->id);
            $purchase->delete();
        });

        return response()->json(['data' => ['id' => $purchase->id, 'deleted' => true]]);
    }

    public function pay(Request $request, MaterialPurchase $purchase): JsonResponse
    {
        $purchase = $this->managed($purchase);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'payment_type' => ['required', Rule::in(FinancePayment::TYPES)],
            'payment_mode' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($purchase, $data, $request) {
            $locked = MaterialPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $due = round((float) $locked->total_amount - (float) $locked->payments()->sum('amount'), 2);
            if ((float) $data['amount'] > $due + 0.001) {
                throw ValidationException::withMessages(['amount' => [__('finance.more_than_due', ['due' => number_format($due, 2)])]]);
            }
            $payment = FinancePayment::create($data + [
                'department_id' => $locked->department_id, 'source_type' => 'material_purchase', 'source_id' => $locked->id, 'recorded_by' => $request->user()->id,
            ]);
            AuditLogger::record('finance', 'payment', $locked, null, ['payment' => $payment->id, 'amount' => $payment->amount]);
        });

        return $this->show($purchase);
    }

    /** Purchases are charged to the department budget in the financial year of their own date. */
    private function charge(MaterialPurchase $purchase, int $userId): void
    {
        $this->ledger->record(Department::query()->findOrFail($purchase->department_id), (float) $purchase->total_amount, 'purchase', 'material_purchase', $purchase->id,
            "{$purchase->material_name} — {$purchase->vendor_name}", $purchase->purchase_date->toDateString(), $userId);
    }

    /** @return Builder<MaterialPurchase> */
    private function scoped(string $permission): Builder
    {
        $ids = ScopeDepartments::ids(AccessContext::current()->scope($permission));

        return MaterialPurchase::query()->when($ids !== null, fn ($q) => $q->whereIn('department_id', $ids ?: [0]));
    }

    private function managed(MaterialPurchase $purchase): MaterialPurchase
    {
        return $this->scoped('finance.manage')->findOrFail($purchase->id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'material_name' => ['required', 'string', 'max:255'],
            'vendor_name' => ['required', 'string', 'max:255'],
            'vendor_contact' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'unit' => ['nullable', 'string', 'max:30'],
            'rate' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'transport_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'gst_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'purchase_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        if (! ScopeDepartments::reaches(AccessContext::current()->scope('finance.manage'), (int) $data['department_id'])) {
            throw ValidationException::withMessages(['department_id' => [__('access.outside_scope')]]);
        }
        $base = round((float) $data['quantity'] * (float) $data['rate'], 2);
        $gst = round($base * (float) ($data['gst_percent'] ?? 0) / 100, 2);
        $transport = round((float) ($data['transport_cost'] ?? 0), 2);

        return $data + [
            'institution_id' => Department::query()->whereKey($data['department_id'])->value('institution_id'),
            'transport_cost' => $transport,
            'gst_percent' => $data['gst_percent'] ?? 0,
            'base_amount' => $base,
            'gst_amount' => $gst,
            'total_amount' => round($base + $transport + $gst, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function present(MaterialPurchase $p, bool $detail = false): array
    {
        $paid = round((float) ($p->paid_amount ?? 0), 2);
        $total = (float) $p->total_amount;

        return [
            'id' => $p->id,
            'department' => $p->department ? ['id' => $p->department->id, 'name' => $p->department->name, 'code' => $p->department->code] : null,
            'material_name' => $p->material_name,
            'vendor_name' => $p->vendor_name,
            'vendor_contact' => $p->vendor_contact,
            'quantity' => (float) $p->quantity,
            'unit' => $p->unit,
            'rate' => (float) $p->rate,
            'base_amount' => (float) $p->base_amount,
            'transport_cost' => (float) $p->transport_cost,
            'gst_percent' => (float) $p->gst_percent,
            'gst_amount' => (float) $p->gst_amount,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'due_amount' => round($total - $paid, 2),
            'payment_status' => $paid <= 0 ? 'pending' : ($paid + 0.001 >= $total ? 'paid' : 'partial'),
            'purchase_date' => $p->purchase_date->toDateString(),
            'remarks' => $p->remarks,
            ...($detail ? [
                'created_by' => $p->creator?->name,
                'payments' => $p->payments->map(fn (FinancePayment $pay) => [
                    'id' => $pay->id, 'amount' => (float) $pay->amount, 'payment_date' => $pay->payment_date->toDateString(), 'payment_type' => $pay->payment_type,
                    'payment_mode' => $pay->payment_mode, 'remarks' => $pay->remarks, 'recorded_by' => $pay->recorder?->name,
                ])->values(),
                'can_manage' => ScopeDepartments::reaches(AccessContext::current()->scope('finance.manage'), $p->department_id),
            ] : []),
        ];
    }

    private function today(): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
    }
}
