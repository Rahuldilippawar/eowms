<?php

namespace App\Support\Access;

use App\Models\LeaveRequest;
use App\Services\LeaveService;
use App\Support\Queries\PeopleQueries;

/** Who may see a leave request (and its attachment). */
final class LeaveAccess
{
    public static function mayView(LeaveRequest $leave): bool
    {
        $context = AccessContext::current();
        if ($context === null) {
            return false;
        }
        $user = $context->user();
        $leave->loadMissing(['employee', 'reviewer']);

        return $leave->employee->user_id === $user->id
            || ($leave->reviewer && $leave->reviewer->user_id === $user->id)
            || ($context->can('leave.view') && PeopleQueries::leave('leave.view')->whereKey($leave->id)->exists())
            || LeaveService::mayDecide($user, $leave);
    }
}
