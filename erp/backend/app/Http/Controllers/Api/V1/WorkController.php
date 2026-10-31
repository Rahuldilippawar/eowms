<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesMyEmployee;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkEntryResource;
use App\Models\Employee;
use App\Models\StoreItem;
use App\Models\Task;
use App\Models\WorkEntry;
use App\Services\WorkImport;
use App\Services\WorkService;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use App\Support\Forms\CustomFields;
use App\Support\Queries\PeopleQueries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Daily work log: the employee's own entries, and the scoped team view with review and export. */
class WorkController extends Controller
{
    use ResolvesMyEmployee;

    public const PRINT_LIMIT = 2000;

    private const WITH = ['employee', 'task', 'reviewer', 'team', 'materials.item:id,item_name,unit'];

    public function __construct(private readonly WorkService $service) {}

    public function mine(Request $request): AnonymousResourceCollection
    {
        $employee = $this->employeeOrFail();
        $f = $this->range($request, $employee->organization, 6);

        $entries = WorkEntry::query()->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$f['from'], $f['to']])
            ->with(self::WITH)
            ->orderByDesc('work_date')->orderByDesc('id')->get();

        return WorkEntryResource::collection($entries)->additional([
            'meta' => ['edit_days' => WorkService::editDays($employee->organization), 'today' => $this->service->today($employee->organization)->toDateString()],
        ]);
    }

    /** Tasks the employee can link a work entry to. */
    public function myTasks(): JsonResponse
    {
        $employee = $this->employeeOrFail();

        return response()->json(['data' => Task::query()->where('assignee_employee_id', $employee->id)
            ->whereIn('status', Task::OPEN_STATES)->orderBy('due_date')->limit(100)->get(['id', 'code', 'title', 'status', 'due_date'])]);
    }

    /** Store items that can be named as material used in a work entry. */
    public function materialOptions(): JsonResponse
    {
        $this->employeeOrFail();

        return response()->json(['data' => StoreItem::query()->where('is_active', true)->orderBy('item_name')->limit(2000)->get(['id', 'item_name', 'unit', 'category'])]);
    }

    /** Active colleagues to name as the team of an entry: name, code and department only. */
    public function colleagues(Request $request): JsonResponse
    {
        $employee = $this->employeeOrFail();
        $f = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $like = '%'.addcslashes($f['search'], '%_\\').'%';

        return response()->json(['data' => Employee::query()->with('department:id,name')->where('is_active', true)->whereKeyNot($employee->id)
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('employee_code', 'like', $like))
            ->orderBy('name')->limit(20)->get()
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->name, 'employee_code' => $e->employee_code, 'department' => $e->department?->name])]);
    }

    public function store(Request $request): WorkEntryResource
    {
        $employee = $this->activeEmployeeOrFail();
        $entry = $this->service->save($employee, $this->validated($request), $request->user());

        return new WorkEntryResource($entry->load(self::WITH));
    }

    public function update(Request $request, WorkEntry $entry): WorkEntryResource
    {
        $employee = $this->activeEmployeeOrFail();
        abort_unless($entry->employee_id === $employee->id, 404);
        $entry = $this->service->save($employee, $this->validated($request), $request->user(), $entry);

        return new WorkEntryResource($entry->load(self::WITH));
    }

    public function destroy(Request $request, WorkEntry $entry): JsonResponse
    {
        $employee = $this->employeeOrFail();
        abort_unless($entry->employee_id === $employee->id, 404);
        $this->service->delete($entry, $request->user());

        return response()->json(['data' => ['id' => $entry->id, 'deleted' => true]]);
    }

    /**
     * Material used in the work of employees in daily_work.view scope (the
     * old material usage report): totals by department and item, and the
     * entries behind them.
     */
    public function materialsReport(Request $request): JsonResponse
    {
        $f = $this->range($request, AccessContext::current()->organization(), 30) + $request->validate(['department_id' => ['nullable', 'integer']]);
        $base = DB::table('work_entry_materials as wm')->join('work_entries as we', 'we.id', '=', 'wm.work_entry_id')->join('store_items as si', 'si.id', '=', 'wm.item_id')
            ->where('we.organization_id', AccessContext::current()->organizationId())
            ->whereIn('we.employee_id', PeopleQueries::employeeIds('daily_work.view'))
            ->whereBetween('we.work_date', [$f['from'], $f['to']])
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('we.department_id', $id));
        $totals = (clone $base)->leftJoin('departments as d', 'd.id', '=', 'we.department_id')
            ->groupBy('we.department_id', 'd.name', 'si.id', 'si.item_name', 'si.unit')->orderBy('d.name')->orderBy('si.item_name')
            ->get(['d.name as department', 'si.item_name', 'si.unit', DB::raw('sum(wm.quantity) as quantity'), DB::raw('count(distinct we.id) as entries')]);
        $rows = (clone $base)->leftJoin('departments as d', 'd.id', '=', 'we.department_id')->join('employees as e', 'e.id', '=', 'we.employee_id')
            ->orderByDesc('we.work_date')->orderByDesc('we.id')->limit(1000)
            ->get(['we.id as entry_id', 'we.work_date', 'we.title', 'd.name as department', 'e.name as employee', 'si.item_name', 'si.unit', 'wm.quantity']);

        return response()->json(['data' => [
            'from' => $f['from'], 'to' => $f['to'],
            'totals' => $totals->map(fn ($t) => ['department' => $t->department, 'item_name' => $t->item_name, 'unit' => $t->unit, 'quantity' => round((float) $t->quantity, 2), 'entries' => (int) $t->entries])->values(),
            'rows' => $rows->map(fn ($r) => ['entry_id' => (int) $r->entry_id, 'work_date' => (string) $r->work_date, 'title' => $r->title, 'department' => $r->department,
                'employee' => $r->employee, 'item_name' => $r->item_name, 'unit' => $r->unit, 'quantity' => round((float) $r->quantity, 2)])->values(),
        ]]);
    }

    /**
     * The printable work report (erp/docs/REPORTS.md): the period's entries with materials and team, and
     * a per-department summary, for the caller's daily_work.view scope.
     */
    public function printReport(Request $request): JsonResponse
    {
        $f = $this->range($request, AccessContext::current()->organization(), 6) + $request->validate([
            'department_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(WorkEntry::STATUSES)],
        ]);
        $query = WorkEntry::query()
            ->whereIn('employee_id', PeopleQueries::employeeIds('daily_work.view'))
            ->whereBetween('work_date', [$f['from'], $f['to']])
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($f['status'] ?? null, fn ($q, $st) => $q->where('status', $st));
        $summary = (clone $query)->leftJoin('departments as d', 'd.id', '=', 'work_entries.department_id')
            ->groupBy('work_entries.department_id', 'd.name')->orderBy('d.name')
            ->get(['d.name as department', DB::raw('count(*) as total'), DB::raw("sum(case when work_entries.status = 'completed' then 1 else 0 end) as completed"),
                DB::raw("sum(case when work_entries.status = 'in_progress' then 1 else 0 end) as in_progress"), DB::raw('coalesce(sum(work_entries.hours), 0) as hours')]);
        $total = (clone $query)->count();
        $entries = $query->with(['employee:id,name,employee_code', 'department:id,name', 'team:employees.id,employees.name', 'materials.item:id,item_name,unit'])
            ->orderBy('work_date')->orderBy('id')->limit(self::PRINT_LIMIT)->get();

        return response()->json(['data' => [
            'from' => $f['from'], 'to' => $f['to'], 'total' => $total, 'truncated' => $total > self::PRINT_LIMIT,
            'summary' => $summary->map(fn ($r) => ['department' => $r->department, 'total' => (int) $r->total, 'completed' => (int) $r->completed,
                'in_progress' => (int) $r->in_progress, 'hours' => round((float) $r->hours, 2),
                'completion' => $r->total > 0 ? (int) round($r->completed * 100 / $r->total) : 0])->values(),
            'entries' => $entries->map(fn (WorkEntry $e) => [
                'id' => $e->id, 'work_date' => $e->work_date->toDateString(), 'title' => $e->title, 'description' => $e->description,
                'hours' => $e->hours === null ? null : (float) $e->hours, 'status' => $e->status, 'reviewed' => $e->reviewed_at !== null,
                'employee' => $e->employee ? ['name' => $e->employee->name, 'code' => $e->employee->employee_code] : null,
                'department' => $e->department?->name,
                'team' => $e->team->pluck('name')->values(),
                'materials' => $e->materials->map(fn ($m) => ['item' => $m->item?->item_name, 'unit' => $m->item?->unit, 'quantity' => round((float) $m->quantity, 2)])->values(),
            ])->values(),
        ]]);
    }

    /** Team view: entries of employees within the caller's daily_work.view scope. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->teamQuery($request, $f);

        return WorkEntryResource::collection(
            $query->with(self::WITH)->orderByDesc('work_date')->orderByDesc('id')->paginate($f['per_page'] ?? 25)->withQueryString()
        );
    }

    public function review(Request $request, WorkEntry $entry): WorkEntryResource
    {
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:500']]);

        return new WorkEntryResource($this->service->review($entry, $data['remarks'] ?? null, $request->user())->load(self::WITH));
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->teamQuery($request, $f);
        $rows = $query->with(['employee', 'task', 'team'])->orderBy('work_date')->orderBy('id')->limit(20000)->get();
        AuditLogger::record('daily_work', 'export', null, null, ['from' => $f['from'], 'to' => $f['to'], 'rows' => $rows->count()]);

        return Csv::download("daily-work-{$f['from']}-{$f['to']}.csv",
            ['date', 'employee_code', 'employee', 'title', 'description', 'hours', 'status', 'task', 'requested_by', 'team',
                'estimated_cost', 'actual_cost', 'reviewed_at', 'review_remarks'],
            $rows->map(fn (WorkEntry $e) => [
                $e->work_date->toDateString(), $e->employee->employee_code, $e->employee->name, $e->title, $e->description,
                $e->hours, $e->status, $e->task?->code, $e->requested_by, $e->team->pluck('employee_code')->implode(', '),
                $e->estimated_cost, $e->actual_cost, $e->reviewed_at?->toDateTimeString(), $e->review_remarks,
            ]));
    }

    /** Before / after photo of the employee's own entry, while it can still be edited. */
    public function uploadPhoto(Request $request, WorkEntry $entry, string $kind): WorkEntryResource
    {
        $employee = $this->activeEmployeeOrFail();
        abort_unless($entry->employee_id === $employee->id, 404);
        $request->validate(['photo' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp']]);

        $this->service->setPhoto($entry, $kind, $request->file('photo'), $request->user());

        return new WorkEntryResource($entry->refresh()->load(self::WITH));
    }

    public function deletePhoto(Request $request, WorkEntry $entry, string $kind): WorkEntryResource
    {
        $employee = $this->employeeOrFail();
        abort_unless($entry->employee_id === $employee->id, 404);
        $this->service->setPhoto($entry, $kind, null, $request->user());

        return new WorkEntryResource($entry->refresh()->load(self::WITH));
    }

    /** The photo file, for the employee, their team mates and anyone whose daily_work.view scope reaches them. */
    public function photo(Request $request, WorkEntry $entry, string $kind): StreamedResponse
    {
        abort_unless($this->service->canSee($entry, $request->user()), 404);
        $path = $entry->{$kind.'_photo_path'};
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function importTemplate(): StreamedResponse
    {
        return Csv::download('daily-work-import-template.csv', WorkImport::COLUMNS, [
            ['2026-03-15', 'EMP-101', 'Pipe repair', 'Fixed the leak in the main building', '2', 'completed', "Principal's office", 'EMP-102, EMP-103', '', '500'],
        ]);
    }

    public function importPreview(Request $request, WorkImport $import): JsonResponse
    {
        $checked = $import->check($this->importRows($request));

        return response()->json(['data' => [
            'rows' => $checked,
            'total' => count($checked),
            'with_errors' => collect($checked)->filter(fn ($r) => $r['errors'] !== [])->count(),
        ]]);
    }

    /** Old work records: every row, or nothing if any row has an error. */
    public function import(Request $request, WorkImport $import): JsonResponse
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
        $request->validate(['file' => ['required', 'file', 'max:4096', 'mimes:csv,txt']]);

        return CsvReader::rows($request->file('file'), WorkImport::REQUIRED, WorkImport::MAX_ROWS);
    }

    /**
     * @param-out array<string, mixed> $f
     *
     * @return Builder<WorkEntry>
     */
    private function teamQuery(Request $request, ?array &$f): Builder
    {
        $f = $this->range($request, AccessContext::current()->organization(), 6) + $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'institution_id' => ['nullable', 'integer'],
            'reviewed' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return WorkEntry::query()
            ->whereIn('employee_id', PeopleQueries::employeeIds('daily_work.view'))
            ->whereBetween('work_date', [$f['from'], $f['to']])
            ->when($f['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['institution_id'] ?? null, fn ($q, $id) => $q->where('institution_id', $id))
            ->when(isset($f['reviewed']), fn ($q) => $f['reviewed'] ? $q->whereNotNull('reviewed_at') : $q->whereNull('reviewed_at'))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('title', 'like', '%'.addcslashes($s, '%_\\').'%')
                ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', '%'.addcslashes($s, '%_\\').'%'))));
    }

    /** @return array{from: string, to: string} at most one year, defaulting to the last $defaultDays + today */
    private function range(Request $request, $organization, int $defaultDays): array
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $today = $this->service->today($organization);
        $to = $f['to'] ?? $today->toDateString();
        $from = $f['from'] ?? CarbonImmutable::parse($to)->subDays($defaultDays)->toDateString();
        $from = max($from, CarbonImmutable::parse($to)->subYear()->toDateString());

        return ['from' => $from, 'to' => $to];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'hours' => ['nullable', 'numeric', 'min:0.25', 'max:24', 'multiple_of:0.25'],
            'status' => ['required', Rule::in(WorkEntry::STATUSES)],
            'task_id' => ['nullable', 'integer'],
            'requested_by' => ['nullable', 'string', 'max:255'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'actual_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'team_employee_ids' => ['sometimes', 'array', 'max:'.WorkEntry::MAX_TEAM],
            'team_employee_ids.*' => ['integer'],
            // Material used (store items); sent = replaces the list.
            'materials' => ['sometimes', 'array', 'max:'.WorkEntry::MAX_MATERIALS],
            'materials.*.item_id' => ['required', 'integer', 'distinct'],
            'materials.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            ...CustomFields::rules('work_entry'),
        ]);
    }
}
