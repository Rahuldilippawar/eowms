<?php

namespace App\Services\Correspondence;

use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use App\Models\Department;
use App\Models\User;

/**
 * Generic approval chains: which step comes next, and who approves it.
 * Used by letters now; other modules can attach their records to a
 * workflow of their own `module`.
 */
final class ApprovalEngine
{
    public function nextStep(ApprovalWorkflow $workflow, int $afterOrder): ?ApprovalWorkflowStep
    {
        return $workflow->steps()->where('step_order', '>', $afterOrder)->orderBy('step_order')->first();
    }

    /** The step before $order, or 0 when $order is the first step (back to the creator). */
    public function previousOrder(ApprovalWorkflow $workflow, int $order): int
    {
        return (int) $workflow->steps()->where('step_order', '<', $order)->max('step_order');
    }

    public function hasNext(ApprovalWorkflow $workflow, int $afterOrder): bool
    {
        return $workflow->steps()->where('step_order', '>', $afterOrder)->exists();
    }

    /** The active user who approves $step, or null when nobody is set (e.g. a department without a head). */
    public function approver(ApprovalWorkflowStep $step, ?int $originDepartmentId): ?User
    {
        $userId = match ($step->approver_type) {
            'user' => $step->user_id,
            'department_head' => $step->department_id ? Department::query()->whereKey($step->department_id)->value('head_user_id') : null,
            'origin_department_head' => $originDepartmentId ? Department::query()->whereKey($originDepartmentId)->value('head_user_id') : null,
            default => null,
        };

        return $userId ? User::query()->whereKey($userId)->where('is_active', true)->first() : null;
    }
}
