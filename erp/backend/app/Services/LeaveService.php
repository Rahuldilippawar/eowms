<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Access\AccessContext;
use App\Support\Access\Reach;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeaveService
{
    /** Working days in the range: weekly offs and holidays are not counted (erp/docs/ATTENDANCE.md). */
    public static function days(Employee $employee, string $start, string $end): float
    {
        return (float) count(WorkCalendar::workingDays($employee, $start, $end));
    }

    /** @throws ValidationException when the dates overlap a requested or approved leave */
    public static function assertNoOverlap(Employee $employee, string $start, string $end, ?int $ignoreId = null): void
    {
        $overlap = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['requested', 'approved'])
            ->where('start_date', '<=', $end)->where('end_date', '>=', $start)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => [__('leave.overlap')]]);
        }
    }

    /**
     * Approvers: the employee's reporting officer (as recorded on the
     * request), or anyone holding leave.approve whose scope reaches the
     * employee. Never the applicant themself.
     */
    public static function mayDecide(User $actor, LeaveRequest $request): bool
    {
        $employee = $request->employee;
        if ($employee->user_id === $actor->id) {
            return false;
        }
        if ($request->reviewer && $request->reviewer->user_id === $actor->id) {
            return true;
        }
        $scope = AccessContext::current()?->scope('leave.approve');

        return $scope !== null && Reach::department($scope, (int) $employee->department_id);
    }

    public function decide(LeaveRequest $request, bool $approve, ?string $remarks, User $actor): LeaveRequest
    {
        return DB::transaction(function () use ($request, $approve, $remarks, $actor) {
            $request = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'requested') {
                throw ValidationException::withMessages(['status' => [__('leave.already_decided')]]);
            }
            $before = $request->getAttributes();
            $request->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_remarks' => $remarks,
            ])->save();

            if ($approve) {
                $this->markLeaveDays($request, $actor->id);
            }
            AuditLogger::recordChanges('leave', $approve ? 'approve' : 'reject', $request, $before, $remarks);

            return $request;
        });
    }

    /** Days the employee did not check in on become "leave"; days with a check-in are left as they are. */
    private function markLeaveDays(LeaveRequest $request, int $actorId): void
    {
        $employee = $request->employee;
        foreach (WorkCalendar::workingDays($employee, $request->start_date->toDateString(), $request->end_date->toDateString()) as $date) {
            $record = AttendanceRecord::query()->where(['employee_id' => $employee->id, 'work_date' => $date])->lockForUpdate()->first();
            if ($record?->check_in_at) {
                continue;
            }
            $record ??= new AttendanceRecord(['employee_id' => $employee->id, 'organization_id' => $employee->organization_id, 'work_date' => $date]);
            $record->fill([
                'institution_id' => $employee->institution_id,
                'department_id' => $employee->department_id,
                'status' => AttendanceStatus::Leave,
                'source' => 'leave',
                'remarks' => "leave #{$request->id}",
            ]);
            $record->forceFill([$record->exists ? 'updated_by' : 'created_by' => $actorId])->save();
        }
    }
}
