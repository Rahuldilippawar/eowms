<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkRequest;
use App\Models\WorkRequestAction;
use App\Models\WorkRequestComment;
use App\Models\WorkRequestView;
use App\Services\AttendanceRules;
use App\Services\Requests\RequestDesk;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Work requests (tickets) inside the app: anyone raises one for a
 * department; that department (or the assignee) works it; the requester
 * can cancel, close with a rating, or reopen within 7 days.
 */
class WorkRequestController extends Controller
{
    public function __construct(private readonly RequestDesk $desk) {}

    /** The queue: requests the viewer's departments receive (or raised / were given). */
    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...WorkRequest::STATUSES, 'open', 'overdue', 'all'])],
            'department_id' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::in(WorkRequest::CATEGORIES)],
            'priority' => ['nullable', Rule::in(WorkRequest::PRIORITIES)],
            'assigned' => ['nullable', Rule::in(['me'])],
            'mine' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $me = AccessContext::current()->user()->id;
        $base = fn () => $this->desk->visible()
            ->when($request->boolean('mine'), fn ($q) => $q->where('requested_by_user_id', $me))
            ->when(($f['assigned'] ?? null) === 'me', fn ($q) => $q->where('assigned_to_user_id', $me))
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($f['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('request_code', 'like', $like)->orWhere('title', 'like', $like)->orWhere('work_description', 'like', $like)
                    ->orWhere('requester_name', 'like', $like)->orWhere('location', 'like', $like));
            });
        $status = $f['status'] ?? ($request->boolean('mine') ? 'all' : 'open');
        $query = $this->withStatus($base(), $status)->with(['department:id,name', 'assignee:id,name'])
            ->orderByRaw("case when status in ('pending', 'in_progress') then 0 else 1 end")
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('due_at')->orderByDesc('id');

        if (($f['format'] ?? null) === 'csv') {
            abort_unless(AccessContext::current()->can('requests.export'), 403, __('auth.forbidden'));
            $rows = $query->limit(20000)->get();
            AuditLogger::record('requests', 'export', null, null, ['rows' => $rows->count()]);

            return Csv::download('work-requests.csv',
                ['code', 'raised', 'department', 'category', 'priority', 'title', 'description', 'location', 'requester', 'contact', 'assigned_to', 'status', 'due', 'completed', 'cost', 'rating', 'remarks'],
                $rows->map(fn (WorkRequest $r) => [
                    $r->request_code, $r->created_at?->toDateTimeString(), $r->department?->name, $r->category, $r->priority, $r->title, $r->work_description, $r->location,
                    $r->requester_name, $r->requester_contact, $r->assignee?->name, $r->status, $r->due_at?->toDateTimeString(), $r->completed_at?->toDateTimeString(),
                    $r->cost_incurred === null ? '' : (float) $r->cost_incurred, $r->rating, $r->completion_remarks,
                ]));
        }
        $page = $query->paginate(25)->withQueryString();
        $counts = $base()->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending, sum(case when status = 'in_progress' then 1 else 0 end) as in_progress,
            sum(case when status in ('pending', 'in_progress') and due_at < ? then 1 else 0 end) as overdue, sum(case when status = 'completed' then 1 else 0 end) as completed", [now()])->first();

        return response()->json([
            'data' => collect($page->items())->map(fn (WorkRequest $r) => $this->present($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(),
                'counts' => collect($counts?->getAttributes() ?? [])->map(fn ($v) => (int) $v)->all(),
                'can_export' => AccessContext::current()->can('requests.export'),
                'can_view_queue' => AccessContext::current()->can('requests.view')],
        ]);
    }

    public function show(WorkRequest $workRequest): JsonResponse
    {
        $r = $this->desk->visible()->findOrFail($workRequest->id);
        // Staff who can work it count as having seen it (the public tracker shows that it was seen).
        if ($this->desk->canHandle($r)) {
            $now = now();
            DB::table('work_request_views')->upsert(
                [['work_request_id' => $r->id, 'user_id' => AccessContext::current()->user()->id, 'first_viewed_at' => $now, 'last_viewed_at' => $now]],
                ['work_request_id', 'user_id'], ['last_viewed_at'],
            );
        }

        return $this->detail($r);
    }

    public function store(Request $request): JsonResponse
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'category' => ['required', Rule::in(WorkRequest::CATEGORIES)],
            'sub_category' => ['nullable', 'required_if:category,it', Rule::in(WorkRequest::IT_SUB_CATEGORIES)],
            'department_id' => ['nullable', 'required_unless:category,it', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)->where('is_active', true)],
            'title' => ['required', 'string', 'max:255'],
            'work_description' => ['required', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', Rule::in(WorkRequest::PRIORITIES)],
            'requester_contact' => ['nullable', 'string', 'max:100'],
        ]);
        if ($data['category'] !== 'it') {
            $data['sub_category'] = null;
        }
        $data['department_id'] ??= RequestDesk::itDepartmentId($orgId);
        if ($data['department_id'] === null) {
            throw ValidationException::withMessages(['department_id' => [__('requests.no_it_department')]]);
        }
        $user = $request->user();
        $employee = Employee::query()->where('user_id', $user->id)->where('is_active', true)->first();
        $r = $this->desk->create(AccessContext::current()->organization(), $data + [
            'requester_name' => $user->name,
            'requester_contact' => $data['requester_contact'] ?? $employee?->phone,
            'requesting_department_id' => $employee?->department_id,
        ], $user, 'staff');
        AuditLogger::record('requests', 'create', $r, null, ['code' => $r->request_code]);

        return $this->detail($r)->setStatusCode(201);
    }

    /** pending ↔ in progress → completed, with remarks and cost; by the assignee or a manager. */
    public function status(Request $request, WorkRequest $workRequest): JsonResponse
    {
        $r = $this->handled($workRequest);
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'in_progress', 'completed'])],
            'remark' => ['nullable', 'required_if:status,completed', 'string', 'max:2000'],
            'cost_incurred' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);
        if (! $r->isOpen() && ! ($r->status === 'completed' && $data['status'] === 'completed')) {
            throw ValidationException::withMessages(['status' => [__('requests.not_open')]]);
        }
        DB::transaction(function () use ($r, $data, $request) {
            $old = $r->status;
            $done = $data['status'] === 'completed';
            $r->forceFill([
                'status' => $data['status'],
                'completed_by' => $done ? ($r->completed_by ?? $request->user()->id) : null,
                'completed_at' => $done ? ($r->completed_at ?? now()) : null,
                'completion_remarks' => $done ? $data['remark'] : $r->completion_remarks,
                'cost_incurred' => array_key_exists('cost_incurred', $data) ? $data['cost_incurred'] : $r->cost_incurred,
            ])->save();
            $this->desk->log($r, 'status_changed', $old, $data['status'], $data['remark'] ?? null, $request->user()->id);
            if ($old !== $data['status']) {
                $this->desk->tellRequester($r, $done ? 'request.completed' : 'request.status', ['status' => $data['status']]);
            }
        });

        return $this->detail($r);
    }

    public function assign(Request $request, WorkRequest $workRequest): JsonResponse
    {
        $r = $this->desk->visible()->findOrFail($workRequest->id);
        abort_unless($this->desk->canAssign($r), 403, __('auth.forbidden'));
        $data = $request->validate(['user_id' => ['nullable', 'integer'], 'remark' => ['nullable', 'string', 'max:500']]);
        if (! empty($data['user_id']) && ! Employee::query()->where('user_id', $data['user_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['user_id' => [__('letters.choose_recipient')]]);
        }
        if (! $r->isOpen()) {
            throw ValidationException::withMessages(['user_id' => [__('requests.not_open')]]);
        }
        DB::transaction(function () use ($r, $data, $request) {
            $r->forceFill(['assigned_to_user_id' => $data['user_id'] ?? null])->save();
            $this->desk->log($r, 'assigned', $r->status, $r->status, $data['remark'] ?? null, $request->user()->id);
            if ($r->assigned_to_user_id) {
                $this->desk->tellHandlers($r, 'request.assigned');
            }
        });

        return $this->detail($r);
    }

    public function comment(Request $request, WorkRequest $workRequest): JsonResponse
    {
        $r = $this->desk->visible()->findOrFail($workRequest->id);
        abort_unless($this->desk->isRequester($r) || $this->desk->canHandle($r), 403, __('auth.forbidden'));
        $data = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        WorkRequestComment::create(['work_request_id' => $r->id, 'user_id' => $request->user()->id, 'comment' => $data['comment']]);
        $extra = ['by' => $request->user()->name];
        $this->desk->isRequester($r) ? $this->desk->tellHandlers($r, 'request.comment', $extra) : $this->desk->tellRequester($r, 'request.comment', $extra);

        return $this->detail($r);
    }

    /** The requester cancels a request nobody has started. */
    public function cancel(Request $request, WorkRequest $workRequest): JsonResponse
    {
        $r = $this->requested($workRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        if ($r->status !== 'pending') {
            throw ValidationException::withMessages(['reason' => [__('requests.cancel_pending_only')]]);
        }
        DB::transaction(function () use ($r, $data, $request) {
            $r->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            $this->desk->log($r, 'cancelled', 'pending', 'cancelled', $data['reason'], $request->user()->id);
            $this->desk->tellHandlers($r, 'request.cancelled');
        });

        return $this->detail($r);
    }

    /** The requester closes a completed request with a 1–5 rating. */
    public function close(Request $request, WorkRequest $workRequest): JsonResponse
    {
        $r = $this->requested($workRequest);
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'feedback' => ['nullable', 'string', 'max:1000']]);
        if ($r->status !== 'completed') {
            throw ValidationException::withMessages(['rating' => [__('requests.close_completed_only')]]);
        }
        DB::transaction(function () use ($r, $data, $request) {
            $r->forceFill(['status' => 'closed', 'closed_at' => now(), 'rating' => $data['rating'], 'feedback' => $data['feedback'] ?? null])->save();
            $this->desk->log($r, 'closed', 'completed', 'closed', "{$data['rating']}/5".(! empty($data['feedback']) ? ' — '.$data['feedback'] : ''), $request->user()->id);
            $this->desk->tellHandlers($r, 'request.closed', ['rating' => $data['rating']]);
        });

        return $this->detail($r);
    }

    /** Within 7 days of completion the requester can reopen it; the SLA starts again. */
    public function reopen(Request $request, WorkRequest $workRequest): JsonResponse
    {
        $r = $this->requested($workRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        if (! $this->desk->canReopen($r)) {
            throw ValidationException::withMessages(['reason' => [__('requests.reopen_window', ['days' => WorkRequest::REOPEN_DAYS])]]);
        }
        DB::transaction(function () use ($r, $data, $request) {
            $old = $r->status;
            $r->forceFill([
                'status' => 'pending', 'completed_by' => null, 'completed_at' => null, 'closed_at' => null, 'rating' => null, 'feedback' => null,
                'reopened_count' => $r->reopened_count + 1, 'due_at' => now()->addHours(WorkRequest::slaHours($r->priority)), 'escalation_level' => 0, 'escalated_at' => null,
            ])->save();
            $this->desk->log($r, 'reopened', $old, 'pending', $data['reason'], $request->user()->id);
            $this->desk->tellHandlers($r, 'request.reopened');
        });

        return $this->detail($r);
    }

    /** Colleagues of the receiving department, to assign the request to. */
    public function assignees(WorkRequest $workRequest): JsonResponse
    {
        $r = $this->desk->visible()->findOrFail($workRequest->id);
        abort_unless($this->desk->canAssign($r), 403, __('auth.forbidden'));

        return response()->json(['data' => Employee::query()->where('is_active', true)->whereNotNull('user_id')->where('department_id', $r->department_id)
            ->orderBy('name')->get(['user_id', 'name', 'designation'])
            ->map(fn (Employee $e) => ['user_id' => $e->user_id, 'name' => $e->name, 'designation' => $e->designation])->values()]);
    }

    /** A period's figures: by status and by receiving department (time to finish, cost, rating). */
    public function report(Request $request): JsonResponse
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department_id' => ['nullable', 'integer'],
        ]);
        $today = AttendanceRules::for(AccessContext::current()->organization())->now();
        $from = $f['from'] ?? $today->startOfMonth()->toDateString();
        $to = $f['to'] ?? $today->toDateString();
        $ids = ScopeDepartments::ids(AccessContext::current()->scope('requests.view'));
        $base = fn () => WorkRequest::query()->when($ids !== null, fn ($q) => $q->whereIn('work_requests.department_id', $ids ?: [0]))
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('work_requests.department_id', $id))
            ->whereBetween('work_requests.created_at', [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay()]);
        $hours = DB::connection()->getDriverName() === 'sqlite'
            ? '(julianday(completed_at) - julianday(work_requests.created_at)) * 24'
            : 'timestampdiff(minute, work_requests.created_at, completed_at) / 60';

        $byStatus = $base()->groupBy('work_requests.status')->selectRaw('work_requests.status, count(*) as total')->pluck('total', 'status')->map(fn ($v) => (int) $v);
        $byDepartment = $base()->join('departments', 'departments.id', '=', 'work_requests.department_id')
            ->groupBy('departments.id', 'departments.name')
            ->selectRaw("departments.id, departments.name, count(*) as total,
                sum(case when work_requests.status in ('completed', 'closed') then 1 else 0 end) as done,
                sum(case when work_requests.status in ('pending', 'in_progress') and work_requests.due_at < ? then 1 else 0 end) as overdue,
                avg(case when work_requests.completed_at is not null then {$hours} end) as avg_hours,
                coalesce(sum(cost_incurred), 0) as cost, avg(rating) as avg_rating", [now()])
            ->orderByDesc('total')->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'total' => (int) $r->total, 'done' => (int) $r->done, 'overdue' => (int) $r->overdue,
                'avg_hours' => $r->avg_hours === null ? null : round((float) $r->avg_hours, 1), 'cost' => round((float) $r->cost, 2),
                'avg_rating' => $r->avg_rating === null ? null : round((float) $r->avg_rating, 1),
            ])->values();
        $byCategory = $base()->groupBy('category')->selectRaw('category, count(*) as total')->pluck('total', 'category')->map(fn ($v) => (int) $v);

        return response()->json(['data' => ['from' => $from, 'to' => $to, 'by_status' => $byStatus, 'by_category' => $byCategory, 'by_department' => $byDepartment]]);
    }

    /** @param  Builder<WorkRequest>  $q */
    private function withStatus(Builder $q, string $status): Builder
    {
        return match ($status) {
            'all' => $q,
            'open' => $q->whereIn('status', WorkRequest::OPEN),
            'overdue' => $q->whereIn('status', WorkRequest::OPEN)->where('due_at', '<', now()),
            default => $q->where('status', $status),
        };
    }

    private function handled(WorkRequest $workRequest): WorkRequest
    {
        $r = $this->desk->visible()->findOrFail($workRequest->id);
        abort_unless($this->desk->canHandle($r), 403, __('auth.forbidden'));

        return $r;
    }

    private function requested(WorkRequest $workRequest): WorkRequest
    {
        $r = $this->desk->visible()->findOrFail($workRequest->id);
        abort_unless($this->desk->isRequester($r), 403, __('auth.forbidden'));

        return $r;
    }

    /** @return array<string, mixed> */
    private function present(WorkRequest $r): array
    {
        $open = $r->isOpen();

        return [
            'id' => $r->id,
            'request_code' => $r->request_code,
            'department' => $r->department ? ['id' => $r->department->id, 'name' => $r->department->name] : null,
            'category' => $r->category,
            'sub_category' => $r->sub_category,
            'title' => $r->title,
            'work_description' => $r->work_description,
            'location' => $r->location,
            'priority' => $r->priority,
            'status' => $r->status,
            'source' => $r->source,
            'requester_name' => $r->requester_name,
            'assignee' => $r->assignee ? ['id' => $r->assignee->id, 'name' => $r->assignee->name] : null,
            'due_at' => $r->due_at?->toIso8601String(),
            'overdue' => $open && $r->due_at !== null && $r->due_at->isPast(),
            'escalation_level' => $r->escalation_level,
            'created_at' => $r->created_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'rating' => $r->rating,
        ];
    }

    private function detail(WorkRequest $r): JsonResponse
    {
        $r->load(['department:id,name', 'assignee:id,name', 'completer:id,name', 'requestingDepartment:id,name', 'actions.user:id,name', 'comments.user:id,name', 'views.user:id,name']);

        return response()->json(['data' => $this->present($r) + [
            'requester_contact' => $r->requester_contact,
            'requesting_department' => $r->requestingDepartment?->name,
            'cost_incurred' => $r->cost_incurred === null ? null : (float) $r->cost_incurred,
            'completion_remarks' => $r->completion_remarks,
            'completed_by' => $r->completer?->name,
            'feedback' => $r->feedback,
            'reopened_count' => $r->reopened_count,
            'actions' => $r->actions->map(fn (WorkRequestAction $a) => [
                'type' => $a->action_type, 'old_status' => $a->old_status, 'new_status' => $a->new_status, 'remark' => $a->remark, 'by' => $a->user?->name, 'at' => $a->created_at?->toIso8601String(),
            ])->values(),
            'comments' => $r->comments->map(fn (WorkRequestComment $c) => ['id' => $c->id, 'comment' => $c->comment, 'by' => $c->user?->name, 'at' => $c->created_at?->toIso8601String()])->values(),
            'seen_by' => $r->views->map(fn (WorkRequestView $v) => ['name' => $v->user?->name, 'at' => $v->first_viewed_at?->toIso8601String()])->values(),
            'can_handle' => $this->desk->canHandle($r),
            'can_assign' => $this->desk->canAssign($r),
            'is_requester' => $this->desk->isRequester($r),
            'can_reopen' => $this->desk->isRequester($r) && $this->desk->canReopen($r),
        ]]);
    }
}
