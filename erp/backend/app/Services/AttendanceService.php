<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Check-in / check-out and manual marking. All times come from the server
 * clock; the client never sends a timestamp for self check-in.
 */
final class AttendanceService
{
    public function checkIn(Employee $employee): AttendanceRecord
    {
        $rules = AttendanceRules::for($employee->organization);
        $now = $rules->now();
        $date = $rules->workDate($now);

        try {
            return DB::transaction(function () use ($employee, $rules, $now, $date) {
                $existing = AttendanceRecord::query()->where(['employee_id' => $employee->id, 'work_date' => $date])->lockForUpdate()->first();
                if ($existing?->check_in_at) {
                    throw ValidationException::withMessages(['attendance' => [__('attendance.already_checked_in')]]);
                }
                if ($existing?->status === AttendanceStatus::Leave) {
                    throw ValidationException::withMessages(['attendance' => [__('attendance.on_leave')]]);
                }

                $record = $existing ?? new AttendanceRecord([
                    'employee_id' => $employee->id,
                    'organization_id' => $employee->organization_id,
                    'work_date' => $date,
                ]);
                $record->fill([
                    'institution_id' => $employee->institution_id,
                    'department_id' => $employee->department_id,
                    'check_in_at' => $now->utc(),
                    'status' => $rules->isLate($now) ? AttendanceStatus::Late : AttendanceStatus::Present,
                    'source' => 'self',
                ]);
                $record->save();

                return $record;
            });
        } catch (QueryException $e) {
            // Two simultaneous check-ins: the unique (employee, date) key rejects the second.
            if ($this->isDuplicate($e)) {
                throw ValidationException::withMessages(['attendance' => [__('attendance.already_checked_in')]]);
            }
            throw $e;
        }
    }

    public function checkOut(Employee $employee): AttendanceRecord
    {
        $rules = AttendanceRules::for($employee->organization);
        $now = $rules->now();

        return DB::transaction(function () use ($employee, $rules, $now) {
            // The latest open shift — today's, or yesterday's for overnight work.
            $record = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereNotNull('check_in_at')->whereNull('check_out_at')
                ->where('check_in_at', '>=', $now->subHours($rules->maxShiftHours)->utc())
                ->orderByDesc('check_in_at')->lockForUpdate()->first();

            if ($record === null) {
                throw ValidationException::withMessages(['attendance' => [__('attendance.no_open_check_in')]]);
            }

            $record->check_out_at = $now->utc();
            $hours = CarbonImmutable::parse($record->check_in_at)->diffInMinutes($now) / 60;
            if ($hours < $rules->halfDayBelowHours) {
                $record->status = AttendanceStatus::HalfDay;
            }
            $record->save();

            return $record;
        });
    }

    /**
     * Set a day's attendance by hand (or from an approved correction).
     * Times are local "HH:MM" on the work date; check-out before check-in
     * means the shift ended the next day.
     */
    public function mark(Employee $employee, string $date, AttendanceStatus $status, ?string $checkIn, ?string $checkOut, ?string $remarks, string $source, int $actorId): AttendanceRecord
    {
        $rules = AttendanceRules::for($employee->organization);
        $in = $checkIn ? CarbonImmutable::parse("{$date} {$checkIn}", $rules->timezone) : null;
        $out = $checkOut ? CarbonImmutable::parse("{$date} {$checkOut}", $rules->timezone) : null;
        if ($in && $out && $out->lessThanOrEqualTo($in)) {
            $out = $out->addDay();
        }

        return DB::transaction(function () use ($employee, $date, $status, $in, $out, $remarks, $source, $actorId) {
            $record = AttendanceRecord::query()->where(['employee_id' => $employee->id, 'work_date' => $date])->lockForUpdate()->first();
            $before = $record?->getAttributes();
            $record ??= new AttendanceRecord(['employee_id' => $employee->id, 'organization_id' => $employee->organization_id, 'work_date' => $date]);
            $record->fill([
                'institution_id' => $employee->institution_id,
                'department_id' => $employee->department_id,
                'status' => $status,
                'check_in_at' => $in?->utc(),
                'check_out_at' => $out?->utc(),
                'remarks' => $remarks,
                'source' => $source,
            ]);
            $record->forceFill([$before ? 'updated_by' : 'created_by' => $actorId])->save();

            $before
                ? AuditLogger::recordChanges('attendance', $source === 'correction' ? 'correct' : 'edit', $record, $before, $remarks)
                : AuditLogger::record('attendance', 'mark', $record, null, AuditLogger::snapshot($record), $remarks);

            return $record;
        });
    }

    private function isDuplicate(QueryException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1062], true);
    }
}
