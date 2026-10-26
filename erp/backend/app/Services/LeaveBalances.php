<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Validation\ValidationException;

/**
 * Leave balance per employee, type and calendar year (erp/docs/ATTENDANCE.md):
 * allocated (an allocation row, else the type's days per year) minus approved
 * and pending working days in that year. Unpaid types with no days per year
 * and no allocation (e.g. leave without pay) have no limit.
 */
final class LeaveBalances
{
    /** @return array{allocated: float|null, used: float, pending: float, remaining: float|null} */
    public function balance(Employee $employee, LeaveType $type, int $year, ?int $ignoreRequestId = null): array
    {
        $allocation = LeaveAllocation::withoutGlobalScopes()->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year])->value('days');
        $allocated = $allocation !== null ? (float) $allocation : ((float) $type->days_per_year > 0 || $type->is_paid ? (float) $type->days_per_year : null);

        $used = 0.0;
        $pending = 0.0;
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";
        LeaveRequest::withoutGlobalScopes()->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id])
            ->whereIn('status', ['approved', 'requested'])
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->when($ignoreRequestId, fn ($q, $id) => $q->whereKeyNot($id))
            ->get()->each(function (LeaveRequest $r) use ($employee, $from, $to, &$used, &$pending) {
                $days = count(WorkCalendar::workingDays($employee, max($r->start_date->toDateString(), $from), min($r->end_date->toDateString(), $to)));
                $r->status === 'approved' ? $used += $days : $pending += $days;
            });

        return ['allocated' => $allocated, 'used' => $used, 'pending' => $pending, 'remaining' => $allocated === null ? null : $allocated - $used - $pending];
    }

    /** @throws ValidationException when the request needs more days than are left in any year it touches */
    public function assertEnough(Employee $employee, LeaveType $type, string $start, string $end, ?int $ignoreRequestId = null): void
    {
        for ($year = (int) substr($start, 0, 4); $year <= (int) substr($end, 0, 4); $year++) {
            $balance = $this->balance($employee, $type, $year, $ignoreRequestId);
            if ($balance['remaining'] === null) {
                continue;
            }
            $needed = count(WorkCalendar::workingDays($employee, max($start, "{$year}-01-01"), min($end, "{$year}-12-31")));
            if ($needed > $balance['remaining']) {
                throw ValidationException::withMessages(['leave_type_id' => [__('leave.insufficient_balance', ['left' => max(0, $balance['remaining']), 'year' => $year])]]);
            }
        }
    }

    /** @return list<array<string, mixed>> one row per active leave type */
    public function forEmployee(Employee $employee, int $year): array
    {
        return LeaveType::withoutGlobalScopes()->where(['organization_id' => $employee->organization_id, 'is_active' => true])->orderBy('name')->get()
            ->map(fn (LeaveType $t) => ['leave_type' => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'is_paid' => $t->is_paid]] + $this->balance($employee, $t, $year))
            ->all();
    }
}
