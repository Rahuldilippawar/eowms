<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesMyEmployee;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkEntryResource;
use App\Models\Task;
use App\Models\WorkEntry;
use App\Services\WorkService;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Queries\PeopleQueries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Daily work log: the employee's own entries, and the scoped team view with review and export. */
class WorkController extends Controller
{
    use ResolvesMyEmployee;

    public function __construct(private readonly WorkService $service) {}

    public function mine(Request $request): AnonymousResourceCollection
    {
        $employee = $this->employeeOrFail();
        $f = $this->range($request, $employee->organization, 6);

        $entries = WorkEntry::query()->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$f['from'], $f['to']])
            ->with(['employee', 'task', 'reviewer'])
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

    public function store(Request $request): WorkEntryResource
    {
        $employee = $this->activeEmployeeOrFail();
        $entry = $this->service->save($employee, $this->validated($request), $request->user());

        return new WorkEntryResource($entry->load(['employee', 'task', 'reviewer']));
    }

    public function update(Request $request, WorkEntry $entry): WorkEntryResource
    {
        $employee = $this->activeEmployeeOrFail();
        abort_unless($entry->employee_id === $employee->id, 404);
        $entry = $this->service->save($employee, $this->validated($request), $request->user(), $entry);

        return new WorkEntryResource($entry->load(['employee', 'task', 'reviewer']));
    }

    public function destroy(Request $request, WorkEntry $entry): JsonResponse
    {
        $employee = $this->employeeOrFail();
        abort_unless($entry->employee_id === $employee->id, 404);
        $this->service->delete($entry, $request->user());

        return response()->json(['data' => ['id' => $entry->id, 'deleted' => true]]);
    }

    /** Team view: entries of employees within the caller's daily_work.view scope. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->teamQuery($request, $f);

        return WorkEntryResource::collection(
            $query->with(['employee', 'task', 'reviewer'])->orderByDesc('work_date')->orderByDesc('id')->paginate($f['per_page'] ?? 25)->withQueryString()
        );
    }

    public function review(Request $request, WorkEntry $entry): WorkEntryResource
    {
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:500']]);

        return new WorkEntryResource($this->service->review($entry, $data['remarks'] ?? null, $request->user())->load(['employee', 'task', 'reviewer']));
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->teamQuery($request, $f);
        $rows = $query->with(['employee', 'task'])->orderBy('work_date')->orderBy('id')->limit(20000)->get();
        AuditLogger::record('daily_work', 'export', null, null, ['from' => $f['from'], 'to' => $f['to'], 'rows' => $rows->count()]);

        return Csv::download("daily-work-{$f['from']}-{$f['to']}.csv",
            ['date', 'employee_code', 'employee', 'title', 'description', 'hours', 'status', 'task', 'reviewed_at', 'review_remarks'],
            $rows->map(fn (WorkEntry $e) => [
                $e->work_date->toDateString(), $e->employee->employee_code, $e->employee->name, $e->title, $e->description,
                $e->hours, $e->status, $e->task?->code, $e->reviewed_at?->toDateTimeString(), $e->review_remarks,
            ]));
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
        ]);
    }
}
