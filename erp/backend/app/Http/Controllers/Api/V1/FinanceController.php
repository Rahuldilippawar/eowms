<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BudgetTransaction;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\FinancePayment;
use App\Models\MaterialPurchase;
use App\Services\AttendanceRules;
use App\Services\Finance\BudgetLedger;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Department budgets: allocation, the spend ledger and the per-department overview (`finance.*`). */
class FinanceController extends Controller
{
    public function __construct(private readonly BudgetLedger $ledger) {}

    /** One row per department in scope: given, spent (by category and source), left, and bills paid vs due. */
    public function budgets(Request $request): JsonResponse
    {
        $fy = $this->fy($request);
        $ids = ScopeDepartments::ids(AccessContext::current()->scope('finance.view'));
        $departments = Department::query()->where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('name')->get(['id', 'name', 'code']);
        $deptIds = $departments->pluck('id');

        $allocated = DepartmentBudget::query()->whereIn('department_id', $deptIds)->where('fy_start_year', $fy)->pluck('allocated_amount', 'department_id');
        $spent = BudgetTransaction::query()->whereIn('department_id', $deptIds)->where('fy_start_year', $fy)
            ->selectRaw('department_id, category, source_type, SUM(amount) as total')->groupBy('department_id', 'category', 'source_type')->get()->groupBy('department_id');
        [$from, $to] = [$fy.'-04-01', ($fy + 1).'-03-31'];
        $billed = MaterialPurchase::query()->whereIn('department_id', $deptIds)->whereBetween('purchase_date', [$from, $to])
            ->selectRaw('department_id, SUM(total_amount) as total')->groupBy('department_id')->pluck('total', 'department_id');
        $paid = FinancePayment::query()->whereIn('department_id', $deptIds)->where('source_type', 'material_purchase')
            ->whereIn('source_id', MaterialPurchase::query()->whereIn('department_id', $deptIds)->whereBetween('purchase_date', [$from, $to])->select('id'))
            ->selectRaw('department_id, SUM(amount) as total')->groupBy('department_id')->pluck('total', 'department_id');

        $rows = $departments->map(function (Department $d) use ($allocated, $spent, $billed, $paid) {
            $lines = $spent->get($d->id, collect());
            $total = round((float) $lines->sum('total'), 2);
            $given = $allocated->has($d->id) ? (float) $allocated[$d->id] : null;

            return [
                'department' => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code],
                'allocated' => $given,
                'spent' => $total,
                'remaining' => $given === null ? null : round($given - $total, 2),
                'percent_used' => $given ? min(999, (int) round($total / $given * 100)) : null,
                'by_category' => $lines->groupBy('category')->map(fn ($g) => round((float) $g->sum('total'), 2)),
                'by_source' => $lines->groupBy('source_type')->map(fn ($g) => round((float) $g->sum('total'), 2)),
                'purchases_billed' => round((float) ($billed[$d->id] ?? 0), 2),
                'purchases_paid' => round((float) ($paid[$d->id] ?? 0), 2),
            ];
        })->values();

        return response()->json(['data' => $rows, 'meta' => [
            'fy' => $fy,
            'current_fy' => $this->currentFy(),
            'can_allocate' => AccessContext::current()->can('finance.budget'),
        ]]);
    }

    public function allocate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', AccessContext::current()->organizationId())],
            'fy' => ['required', 'integer', 'min:2000', 'max:2100'],
            'allocated_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ]);
        if (! ScopeDepartments::reaches(AccessContext::current()->scope('finance.budget'), (int) $data['department_id'])) {
            throw ValidationException::withMessages(['department_id' => [__('access.outside_scope')]]);
        }
        $budget = DepartmentBudget::query()->firstOrNew(['department_id' => $data['department_id'], 'fy_start_year' => $data['fy']]);
        $before = $budget->exists ? $budget->getAttributes() : [];
        $budget->forceFill(['allocated_amount' => $data['allocated_amount'], 'allocated_by' => $request->user()->id])->save();
        AuditLogger::recordChanges('finance', 'budget_allocate', $budget, $before);

        return response()->json(['data' => ['department_id' => $budget->department_id, 'fy' => $budget->fy_start_year, 'allocated_amount' => (float) $budget->allocated_amount]]);
    }

    /** Before recording a spend: what is left in the department's budget for that date's financial year. */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $context = AccessContext::current();
        $reach = ScopeDepartments::reaches($context->scope('finance.manage'), (int) $data['department_id'])
            || ScopeDepartments::reaches($context->scope('finance.view'), (int) $data['department_id']);
        abort_unless(($context->can('finance.manage') || $context->can('finance.view')) && $reach, 404);
        $date = $data['date'] ?? $this->today();
        $remaining = $this->ledger->remaining((int) $data['department_id'], $date);

        return response()->json(['data' => [
            'fy' => BudgetLedger::fyOf($date),
            'remaining' => $remaining,
            'exceeds' => $remaining !== null && (float) ($data['amount'] ?? 0) > $remaining,
        ]]);
    }

    /** The spend ledger (every row against a budget); CSV with `finance.export`. */
    public function transactions(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'fy' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'department_id' => ['nullable', 'integer'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $fy = (int) ($f['fy'] ?? $this->currentFy());
        $csv = ($f['format'] ?? null) === 'csv';
        abort_if($csv && ! AccessContext::current()->can('finance.export'), 403, __('auth.forbidden'));
        $ids = ScopeDepartments::ids(AccessContext::current()->scope('finance.view'));
        $rows = BudgetTransaction::query()->with('department:id,name,code')->where('fy_start_year', $fy)
            ->when($ids !== null, fn ($q) => $q->whereIn('department_id', $ids ?: [0]))
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->orderBy('spend_date')->orderBy('id')->limit(20000)->get();

        if ($csv) {
            AuditLogger::record('finance', 'export', null, null, ['fy' => $fy, 'rows' => $rows->count()]);

            return Csv::download(sprintf('budget-ledger-%d-%02d.csv', $fy, ($fy + 1) % 100), ['date', 'department', 'category', 'source', 'description', 'amount'],
                $rows->map(fn (BudgetTransaction $t) => [$t->spend_date->toDateString(), $t->department?->name, $t->category, $t->source_type, $t->description, $t->amount]));
        }

        return response()->json(['data' => $rows->map(fn (BudgetTransaction $t) => [
            'id' => $t->id, 'date' => $t->spend_date->toDateString(), 'department' => $t->department?->name, 'category' => $t->category,
            'source_type' => $t->source_type, 'source_id' => $t->source_id, 'description' => $t->description, 'amount' => (float) $t->amount,
        ])->values(), 'meta' => ['fy' => $fy]]);
    }

    private function fy(Request $request): int
    {
        $f = $request->validate(['fy' => ['nullable', 'integer', 'min:2000', 'max:2100']]);

        return (int) ($f['fy'] ?? $this->currentFy());
    }

    private function today(): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
    }

    private function currentFy(): int
    {
        return BudgetLedger::fyOf($this->today());
    }
}
