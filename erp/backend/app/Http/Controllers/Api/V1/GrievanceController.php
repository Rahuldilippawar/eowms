<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Grievance;
use App\Models\GrievanceComment;
use App\Services\AttendanceRules;
use App\Services\Notifier;
use App\Support\Access\AccessContext;
use App\Support\Access\PermissionHolders;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Grievances (the old complaints page). Anyone in the organization submits
 * one, optionally anonymously: then only a keyed hash of the person is kept,
 * so they still see and follow up their own grievance while nobody else can
 * tell who sent it. grievances.manage holders handle them; the person they
 * assign can work on one too. Seven days to resolve.
 */
class GrievanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...Grievance::STATUSES, 'overdue', 'all'])],
            'mine' => ['nullable', 'boolean'],
        ]);
        $status = $f['status'] ?? 'all';
        $today = $this->today();
        $rows = $this->visible()
            ->when($request->boolean('mine'), fn ($q) => $this->ownedBy($q))
            ->when($status === 'overdue', fn ($q) => $q->whereIn('status', Grievance::OPEN)->where('sla_deadline', '<', $today))
            ->when(! in_array($status, ['all', 'overdue'], true), fn ($q) => $q->where('status', $status))
            ->with(['assignee:id,name', 'submitter:id,name'])
            ->orderByRaw("case status when 'open' then 0 when 'in_progress' then 1 when 'resolved' then 2 else 3 end")->orderByDesc('id')
            ->limit(300)->get();
        $handler = $this->isHandler();

        return response()->json([
            'data' => $rows->map(fn (Grievance $g) => $this->present($g))->values(),
            'meta' => [
                'is_handler' => $handler,
                'open' => $rows->whereIn('status', Grievance::OPEN)->count(),
                'overdue' => $rows->filter(fn (Grievance $g) => in_array($g->status, Grievance::OPEN, true) && $g->sla_deadline && $g->sla_deadline->toDateString() < $today)->count(),
            ],
        ]);
    }

    public function show(Grievance $grievance): JsonResponse
    {
        return $this->detail($this->visible()->findOrFail($grievance->id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'is_anonymous' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $anonymous = (bool) ($data['is_anonymous'] ?? false);
        $g = Grievance::create([
            'category' => $data['category'] ?? null,
            'description' => $data['description'],
            'is_anonymous' => $anonymous,
            'submitted_by' => $anonymous ? null : $user->id,
            'submitter_hash' => $anonymous ? Grievance::submitterHash($user->id) : null,
            'status' => 'open',
            'sla_deadline' => $this->today(Grievance::SLA_DAYS),
        ]);
        // Audit rows carry the acting user, so an anonymous sender's own steps are not audited.
        if (! $anonymous) {
            AuditLogger::record('grievances', 'create', $g);
        }
        foreach ($this->handlers() as $userId) {
            Notifier::user($userId, $g->organization_id, 'grievance.new', ['id' => $g->id, 'category' => $g->category ?? ''], "/grievances/{$g->id}");
        }

        return $this->detail($g)->setStatusCode(201);
    }

    /** Status, resolution and assignment: handlers; the assignee can change status and resolution only. */
    public function update(Request $request, Grievance $grievance): JsonResponse
    {
        $g = $this->visible()->findOrFail($grievance->id);
        $handler = $this->isHandler();
        abort_unless($handler || $this->isAssignee($g), 403, __('auth.forbidden'));
        $data = $request->validate([
            'status' => ['required', Rule::in(Grievance::STATUSES)],
            'resolution' => ['nullable', 'required_if:status,resolved', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer'],
        ]);
        if (array_key_exists('assigned_to', $data) && ! $handler && $data['assigned_to'] !== $g->assigned_to) {
            throw ValidationException::withMessages(['assigned_to' => [__('auth.forbidden')]]);
        }
        if (! empty($data['assigned_to']) && ! Employee::query()->where('user_id', $data['assigned_to'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['assigned_to' => [__('letters.choose_recipient')]]);
        }
        DB::transaction(function () use ($g, $data) {
            $before = $g->getAttributes();
            $assignee = array_key_exists('assigned_to', $data) ? $data['assigned_to'] : $g->assigned_to;
            $g->forceFill([
                'status' => $data['status'],
                'resolution' => $data['resolution'] ?? $g->resolution,
                'assigned_to' => $assignee,
                'resolved_at' => $data['status'] === 'resolved' ? ($g->resolved_at ?? now()) : (in_array($data['status'], Grievance::OPEN, true) ? null : $g->resolved_at),
            ])->save();
            AuditLogger::recordChanges('grievances', 'update', $g, $before);
            $link = "/grievances/{$g->id}";
            if ($assignee && $assignee !== ($before['assigned_to'] ?? null)) {
                Notifier::user($assignee, $g->organization_id, 'grievance.assigned', ['id' => $g->id, 'category' => $g->category ?? ''], $link);
            }
            if ($g->submitted_by && $data['status'] !== $before['status']) {
                Notifier::user($g->submitted_by, $g->organization_id, 'grievance.status', ['id' => $g->id, 'status' => $data['status']], $link);
            }
        });

        return $this->detail($g);
    }

    public function comment(Request $request, Grievance $grievance): JsonResponse
    {
        $g = $this->visible()->findOrFail($grievance->id);
        $mine = $this->isSubmitter($g);
        abort_unless($mine || $this->isHandler() || $this->isAssignee($g), 403, __('auth.forbidden'));
        $data = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        GrievanceComment::create([
            'grievance_id' => $g->id,
            'user_id' => $mine && $g->is_anonymous ? null : $request->user()->id,
            'author_role' => $mine ? 'submitter' : 'handler',
            'comment' => $data['comment'],
        ]);
        $link = "/grievances/{$g->id}";
        if ($mine) {
            foreach ($g->assigned_to ? [$g->assigned_to] : $this->handlers() as $userId) {
                Notifier::user($userId, $g->organization_id, 'grievance.comment', ['id' => $g->id], $link);
            }
        } else {
            Notifier::user($g->submitted_by, $g->organization_id, 'grievance.reply', ['id' => $g->id], $link);
        }

        return $this->detail($g);
    }

    public function reopen(Request $request, Grievance $grievance): JsonResponse
    {
        $g = $this->visible()->findOrFail($grievance->id);
        abort_unless($this->isSubmitter($g), 403, __('auth.forbidden'));
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        if (! in_array($g->status, ['resolved', 'closed'], true)) {
            throw ValidationException::withMessages(['reason' => [__('grievances.not_resolved')]]);
        }
        DB::transaction(function () use ($g, $data, $request) {
            $g->forceFill([
                'status' => 'open', 'resolved_at' => null, 'escalated_at' => null, 'reopened_count' => $g->reopened_count + 1, 'sla_deadline' => $this->today(Grievance::SLA_DAYS),
            ])->save();
            GrievanceComment::create(['grievance_id' => $g->id, 'user_id' => $g->is_anonymous ? null : $request->user()->id, 'author_role' => 'submitter', 'comment' => $data['reason']]);
            if (! $g->is_anonymous) {
                AuditLogger::record('grievances', 'reopen', $g);
            }
        });
        foreach ($g->assigned_to ? [$g->assigned_to] : $this->handlers() as $userId) {
            Notifier::user($userId, $g->organization_id, 'grievance.reopened', ['id' => $g->id], "/grievances/{$g->id}");
        }

        return $this->detail($g);
    }

    /** Handlers see every grievance; others their own (also anonymous ones) and those assigned to them. @return Builder<Grievance> */
    private function visible(): Builder
    {
        if ($this->isHandler()) {
            return Grievance::query();
        }

        return Grievance::query()->where(fn ($w) => $this->ownedBy($w)->orWhere('assigned_to', AccessContext::current()->user()->id));
    }

    /** @param  Builder<Grievance>  $q */
    private function ownedBy(Builder $q): Builder
    {
        $id = AccessContext::current()->user()->id;

        return $q->where(fn ($w) => $w->where('submitted_by', $id)->orWhere('submitter_hash', Grievance::submitterHash($id)));
    }

    private function isHandler(): bool
    {
        return AccessContext::current()->can('grievances.manage');
    }

    private function isAssignee(Grievance $g): bool
    {
        return $g->assigned_to !== null && $g->assigned_to === AccessContext::current()->user()->id;
    }

    private function isSubmitter(Grievance $g): bool
    {
        $id = AccessContext::current()->user()->id;

        return $g->submitted_by === $id || ($g->submitter_hash !== null && hash_equals($g->submitter_hash, Grievance::submitterHash($id)));
    }

    /** @return list<int> */
    private function handlers(): array
    {
        return PermissionHolders::userIds(AccessContext::current()->organizationId(), 'grievances.manage');
    }

    private function today(int $plusDays = 0): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->addDays($plusDays)->toDateString();
    }

    /** @return array<string, mixed> */
    private function present(Grievance $g): array
    {
        $open = in_array($g->status, Grievance::OPEN, true);

        return [
            'id' => $g->id,
            'category' => $g->category,
            'description' => $g->description,
            'is_anonymous' => $g->is_anonymous,
            // Anonymous: no name, ever — not even to handlers.
            'submitted_by' => $g->is_anonymous ? null : $g->submitter?->name,
            'is_mine' => $this->isSubmitter($g),
            'status' => $g->status,
            'assignee' => $g->assignee ? ['id' => $g->assignee->id, 'name' => $g->assignee->name] : null,
            'resolution' => $g->resolution,
            'sla_deadline' => $g->sla_deadline?->toDateString(),
            'overdue' => $open && $g->sla_deadline !== null && $g->sla_deadline->toDateString() < $this->today(),
            'escalated' => $g->escalated_at !== null,
            'reopened_count' => $g->reopened_count,
            'created_at' => $g->created_at?->toIso8601String(),
            'resolved_at' => $g->resolved_at?->toIso8601String(),
        ];
    }

    private function detail(Grievance $g): JsonResponse
    {
        $g->load(['assignee:id,name', 'submitter:id,name', 'comments.user:id,name']);
        $handler = $this->isHandler();
        $mine = $this->isSubmitter($g);

        return response()->json(['data' => $this->present($g) + [
            'comments' => $g->comments->map(fn (GrievanceComment $c) => [
                'id' => $c->id, 'comment' => $c->comment, 'author_role' => $c->author_role,
                'by' => $c->author_role === 'submitter' && $g->is_anonymous ? null : $c->user?->name,
                'at' => $c->created_at?->toIso8601String(),
            ])->values(),
            'can_update' => $handler || $this->isAssignee($g),
            'can_assign' => $handler,
            'can_comment' => $mine || $handler || $this->isAssignee($g),
            'can_reopen' => $mine && in_array($g->status, ['resolved', 'closed'], true),
        ]]);
    }
}
