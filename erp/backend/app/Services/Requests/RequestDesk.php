<?php

namespace App\Services\Requests;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestAction;
use App\Services\Notifier;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Numbering\NumberSequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Work requests (erp/docs/REQUESTS.md): numbering, SLA, who sees and handles
 * which request, and the action log that the public tracker shows.
 */
final class RequestDesk
{
    /** Requests the viewer raised, is assigned, or that the departments in their scope receive. @return Builder<WorkRequest> */
    public function visible(): Builder
    {
        $ctx = AccessContext::current();
        $me = $ctx->user()->id;
        $ids = ScopeDepartments::ids($ctx->can('requests.view') ? $ctx->scope('requests.view') : null);
        if ($ids === null) {
            return WorkRequest::query();
        }

        return WorkRequest::query()->where(fn ($w) => $w->where('requested_by_user_id', $me)->orWhere('assigned_to_user_id', $me)->orWhereIn('department_id', $ids ?: [0]));
    }

    /** Status, cost and remarks: the assignee, or requests.manage over the receiving department. */
    public function canHandle(WorkRequest $r): bool
    {
        $ctx = AccessContext::current();

        return $r->assigned_to_user_id === $ctx->user()->id || $this->canAssign($r);
    }

    /** Assigning needs requests.manage over the receiving department (an assignee cannot pass it on). */
    public function canAssign(WorkRequest $r): bool
    {
        return ScopeDepartments::reaches(AccessContext::current()->scope('requests.manage'), $r->department_id);
    }

    public function isRequester(WorkRequest $r): bool
    {
        return $r->requested_by_user_id !== null && $r->requested_by_user_id === AccessContext::current()->user()->id;
    }

    public function canReopen(WorkRequest $r): bool
    {
        return in_array($r->status, ['completed', 'closed'], true) && $r->completed_at !== null
            && $r->completed_at->greaterThanOrEqualTo(now()->subDays(WorkRequest::REOPEN_DAYS));
    }

    /** The organization's IT department (code IT), which receives IT requests unless one is chosen. */
    public static function itDepartmentId(int $organizationId): ?int
    {
        return Department::withoutGlobalScopes()->where('organization_id', $organizationId)->where('is_active', true)
            ->whereRaw('upper(code) = ?', ['IT'])->orderBy('id')->value('id');
    }

    /** @param  array<string, mixed>  $data */
    public function create(Organization $organization, array $data, ?User $by, string $source): WorkRequest
    {
        return DB::transaction(function () use ($organization, $data, $by, $source) {
            $now = CarbonImmutable::now();
            $year = $now->format('y');
            $code = sprintf('REQ-%s-%04d', $year, NumberSequence::next($organization->id, 'work_request', $year));
            $priority = $data['priority'] ?? 'normal';
            $department = Department::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($data['department_id']);
            $request = WorkRequest::create([
                ...$data,
                'organization_id' => $organization->id,
                'request_code' => $code,
                'institution_id' => $department->institution_id,
                'priority' => $priority,
                'due_at' => $now->addHours(WorkRequest::slaHours($priority)),
                'source' => $source,
                'requested_by_user_id' => $by?->id,
                'status' => 'pending',
            ]);
            $this->log($request, 'created', null, 'pending', null, $by?->id);
            $data = ['code' => $code, 'title' => $this->label($request)];
            Notifier::user($department->head_user_id, $organization->id, 'request.new', $data, "/requests/{$request->id}");
            if ($request->assigned_to_user_id) {
                Notifier::user($request->assigned_to_user_id, $organization->id, 'request.assigned', $data, "/requests/{$request->id}");
            }

            return $request;
        });
    }

    public function log(WorkRequest $r, string $type, ?string $old, ?string $new, ?string $remark, ?int $userId): void
    {
        WorkRequestAction::create(['work_request_id' => $r->id, 'action_type' => $type, 'old_status' => $old, 'new_status' => $new, 'remark' => $remark, 'changed_by' => $userId]);
    }

    /** Tells the requester (a signed-in one) about a change made by someone else. */
    public function tellRequester(WorkRequest $r, string $type, array $extra = []): void
    {
        Notifier::user($r->requested_by_user_id, $r->organization_id, $type, ['code' => $r->request_code, 'title' => $this->label($r), ...$extra], "/requests/{$r->id}");
    }

    /** Tells whoever works on it: the assignee, else the receiving department head. */
    public function tellHandlers(WorkRequest $r, string $type, array $extra = []): void
    {
        $to = $r->assigned_to_user_id ?? Department::withoutGlobalScopes()->whereKey($r->department_id)->value('head_user_id');
        Notifier::user($to, $r->organization_id, $type, ['code' => $r->request_code, 'title' => $this->label($r), ...$extra], "/requests/{$r->id}");
    }

    public function label(WorkRequest $r): string
    {
        return mb_substr($r->title ?: $r->work_description, 0, 80);
    }
}
