<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesMyEmployee;
use App\Http\Controllers\Controller;
use App\Services\AttendanceRules;
use App\Services\PerformanceReport;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Queries\PeopleQueries;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PerformanceController extends Controller
{
    use ResolvesMyEmployee;

    public function __construct(private readonly PerformanceReport $report) {}

    public function me(Request $request): JsonResponse
    {
        $employee = $this->employeeOrFail()->load('department');
        [$from, $to, $today] = $this->range($request);

        return response()->json(['data' => $this->report->rows(collect([$employee]), $from, $to, $today)->first(), 'from' => $from, 'to' => $to]);
    }

    /** Employees within performance.view; `format=csv` (performance.export) returns every matching row. */
    public function index(Request $request): JsonResponse|StreamedResponse
    {
        [$from, $to, $today] = $this->range($request);
        $f = $request->validate([
            'department_id' => ['nullable', 'integer'],
            'institution_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);
        $query = PeopleQueries::employees('performance.view')->where('employees.is_active', true)->with('department')
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('employees.department_id', $id))
            ->when($f['institution_id'] ?? null, fn ($q, $id) => $q->where('employees.institution_id', $id))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('employees.name', 'like', '%'.addcslashes($s, '%_\\').'%')->orWhere('employees.employee_code', $s)))
            ->orderBy('employees.name');

        if (($f['format'] ?? 'json') === 'csv') {
            abort_unless(AccessContext::current()->can('performance.export'), 403, __('auth.forbidden'));
            $rows = $this->report->rows($query->limit(5000)->get(), $from, $to, $today);
            AuditLogger::record('performance', 'export', null, null, ['from' => $from, 'to' => $to, 'rows' => $rows->count()]);

            return Csv::download("performance-{$from}-{$to}.csv",
                ['employee_code', 'employee', 'department', 'present', 'late', 'half_day', 'absent', 'leave', 'work_entries', 'work_hours', 'days_logged', 'tasks_assigned', 'tasks_completed', 'tasks_on_time', 'tasks_overdue', 'on_time_rate'],
                $rows->map(fn ($r) => [
                    $r['employee']['employee_code'], $r['employee']['name'], $r['employee']['department'],
                    ...array_values($r['attendance']), $r['work']['entries'], $r['work']['hours'], $r['work']['days_logged'],
                    $r['tasks']['assigned'], $r['tasks']['completed'], $r['tasks']['on_time'], $r['tasks']['overdue'], $r['tasks']['on_time_rate'],
                ]));
        }

        $page = $query->paginate($f['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => $this->report->rows(collect($page->items()), $from, $to, $today),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** @return array{string, string, string} from, to (≤ 1 year, default this month) and the organization's today */
    private function range(Request $request): array
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $now = AttendanceRules::for(AccessContext::current()->organization())->now();
        $to = $f['to'] ?? $now->toDateString();
        $from = $f['from'] ?? $now->startOfMonth()->toDateString();

        return [max($from, CarbonImmutable::parse($to)->subYear()->toDateString()), $to, $now->toDateString()];
    }
}
