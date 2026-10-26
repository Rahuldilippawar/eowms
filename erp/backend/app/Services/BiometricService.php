<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns machine punches into attendance (erp/docs/ATTENDANCE.md#biometric):
 * the first punch of the day is the check-in, the last one the check-out.
 * Punch times are the device's local time, read in the organization's timezone.
 */
final class BiometricService
{
    /** Parses an ATTLOG body ("PIN\tYYYY-MM-DD HH:MM:SS\t…" per line) and applies each punch. */
    public function ingest(BiometricDevice $device, string $body): int
    {
        $tz = AttendanceRules::for($device->organization)->timezone;
        $count = 0;
        foreach (preg_split('/\r\n|\r|\n/', trim($body)) as $line) {
            $cells = preg_split('/\t+/', trim($line));
            if (count($cells) < 2 || ! preg_match('/^[0-9A-Za-z]{1,20}$/', $cells[0]) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $cells[1])) {
                continue;
            }
            $this->punch($device, $cells[0], CarbonImmutable::parse($cells[1], $tz));
            $count++;
        }

        return $count;
    }

    public function punch(BiometricDevice $device, string $pin, CarbonImmutable $at): string
    {
        $employee = Employee::withoutGlobalScopes()->where(['organization_id' => $device->organization_id, 'biometric_pin' => $pin])->first();
        $result = match (true) {
            $employee === null => 'unknown_pin',
            ! $employee->is_active => 'inactive',
            default => 'applied',
        };
        try {
            BiometricPunch::withoutGlobalScopes()->create([
                'organization_id' => $device->organization_id, 'device_id' => $device->id, 'pin' => $pin,
                'punched_at' => $at->utc(), 'employee_id' => $employee?->id, 'result' => $result,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Devices resend old logs; each punch counts once.
            return 'duplicate';
        }
        if ($result === 'applied') {
            $this->apply($employee, $at);
        }

        return $result;
    }

    private function apply(Employee $employee, CarbonImmutable $at): void
    {
        $rules = AttendanceRules::forEmployee($employee);
        $date = $rules->workDate($at);
        DB::transaction(function () use ($employee, $rules, $at, $date) {
            $record = AttendanceRecord::withoutGlobalScopes()->where(['employee_id' => $employee->id, 'work_date' => $date])->lockForUpdate()->first();
            $record ??= new AttendanceRecord(['employee_id' => $employee->id, 'organization_id' => $employee->organization_id, 'work_date' => $date]);
            $in = $record->check_in_at ? CarbonImmutable::parse($record->check_in_at) : null;
            $out = $record->check_out_at ? CarbonImmutable::parse($record->check_out_at) : null;
            $at = $at->utc();

            if ($in === null || $at->lessThan($in)) {
                // An earlier punch becomes the check-in; the old check-in may become the check-out.
                if ($in !== null && ($out === null || $in->greaterThan($out))) {
                    $out = $in;
                }
                $in = $at;
            } elseif ($in->diffInMinutes($at) >= 1 && ($out === null || $at->greaterThan($out))) {
                $out = $at;
            }

            $status = $rules->isLate($in) ? AttendanceStatus::Late : AttendanceStatus::Present;
            if ($out !== null && $in->diffInMinutes($out) / 60 < $rules->halfDayBelowHours) {
                $status = AttendanceStatus::HalfDay;
            }
            $record->fill([
                'institution_id' => $employee->institution_id, 'department_id' => $employee->department_id,
                'check_in_at' => $in, 'check_out_at' => $out, 'status' => $status, 'source' => 'biometric',
            ])->save();
        });
    }
}
