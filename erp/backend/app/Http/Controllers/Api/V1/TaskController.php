<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\AttendanceRules;
use App\Services\TaskService;
use App\Support\Access\AccessContext;
use App\Support\Queries\PeopleQueries;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Tasks: who may see / assign / manage is decided by TaskService (docs/WORK-AND-TASKS.md). */
class TaskController extends Controller
{
    public function __construct(private readonly TaskService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'view' => ['nullable', Rule::in(['mine', 'assigned', 'all'])],
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'submitted', 'completed', 'cancelled', 'active'])],
            'overdue' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();
        $today = $this->today($request);
        $mine = PeopleQueries::myEmployee();

        $query = TaskService::visible($user)
            ->when(($f['view'] ?? 'all') === 'mine', fn ($q) => $q->where('assignee_employee_id', $mine?->id ?? 0))
            ->when(($f['view'] ?? 'all') === 'assigned', fn ($q) => $q->where('assigned_by', $user->id))
            ->when($f['status'] ?? null, fn ($q, $s) => $s === 'active' ? $q->whereIn('status', Task::OPEN_STATES) : $q->where('status', $s))
            ->when($f['overdue'] ?? false, fn ($q) => $q->whereIn('status', Task::OPEN_STATES)->where('due_date', '<', $today))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('title', 'like', '%'.addcslashes($s, '%_\\').'%')->orWhere('code', $s)))
            ->with(['assignee.reportingOfficer', 'assigner', 'department'])
            // Open work first, earliest due date first.
            ->orderByRaw("FIELD(status, 'submitted', 'in_progress', 'open', 'completed', 'cancelled')")
            ->orderBy('due_date')->orderByDesc('id');

        return TaskResource::collection($query->paginate($f['per_page'] ?? 25)->withQueryString());
    }

    /** Employees the caller may assign tasks to. */
    public function assignable(Request $request): JsonResponse
    {
        $rows = TaskService::assignableEmployees($request->user())->with('department')->orderBy('name')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'employee_code' => $e->employee_code, 'department' => $e->department?->name])]);
    }

    public function store(Request $request): TaskResource
    {
        $task = $this->service->create($this->validated($request, true), $request->user());

        return $this->show($request, $task);
    }

    public function show(Request $request, Task $task): TaskResource
    {
        $this->assertVisible($request, $task);
        $this->today($request);

        return new TaskResource($task->load(['assignee.reportingOfficer', 'assigner', 'department', 'updates.user']));
    }

    public function update(Request $request, Task $task): TaskResource
    {
        $this->assertVisible($request, $task);
        $task = $this->service->update($task, $this->validated($request, false), $request->user());

        return $this->show($request, $task);
    }

    public function transition(Request $request, Task $task, string $action): TaskResource
    {
        $this->assertVisible($request, $task);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $task = $this->service->transition($task, $action, $data['note'] ?? null, $request->user());

        return $this->show($request, $task);
    }

    public function comment(Request $request, Task $task): TaskResource
    {
        $this->assertVisible($request, $task);
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);
        $this->service->comment($task, $data['body'], $request->user());

        return $this->show($request, $task);
    }

    private function assertVisible(Request $request, Task $task): void
    {
        abort_unless(TaskService::visible($request->user())->whereKey($task->id)->exists(), 404);
    }

    /** The organization's "today", shared with TaskResource for the overdue flag. */
    private function today(Request $request): string
    {
        $today = AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
        $request->attributes->set('org_today', $today);

        return $today;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $today = $this->today($request);
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$req, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => [$req, Rule::in(Task::PRIORITIES)],
            'due_date' => [$req, 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail) use ($creating, $today) {
                if ($creating && is_string($value) && $value < $today) {
                    $fail(__('tasks.due_in_past'));
                }
            }],
            'assignee_employee_id' => [$req, 'integer'],
        ]);
    }
}
