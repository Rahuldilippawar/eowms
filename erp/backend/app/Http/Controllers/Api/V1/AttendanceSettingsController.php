<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Services\AttendanceRules;
use App\Services\WorkService;
use App\Support\Access\AccessContext;
use App\Support\Access\EmployeeAccess;
use App\Support\Audit\AuditLogger;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The organization's attendance rule and per-employee shift / biometric PIN. */
class AttendanceSettingsController extends Controller
{
    public function organization(): JsonResponse
    {
        $organization = AccessContext::current()->organization();

        return response()->json(['data' => AttendanceRules::settings($organization) + ['daily_work_edit_days' => WorkService::editDays($organization)]]);
    }

    public function updateOrganization(Request $request): JsonResponse
    {
        abort_unless(AccessContext::current()->scope('organizations.edit')?->isOrganizationWide(), 403, __('auth.forbidden'));
        $data = $request->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            ...$this->shiftRules(true),
            'max_shift_hours' => ['required', 'integer', 'min:4', 'max:24'],
            'daily_work_edit_days' => ['required', 'integer', 'min:0', 'max:31'],
        ]);
        $organization = AccessContext::current()->organization();
        $settings = $organization->settings ?? [];
        $before = ['attendance' => $settings['attendance'] ?? null, 'daily_work' => $settings['daily_work'] ?? null];
        $settings['attendance'] = collect($data)->except('daily_work_edit_days')->all();
        $settings['attendance']['weekly_offs'] = array_values(array_unique(array_map('intval', $data['weekly_offs'])));
        $settings['daily_work'] = ['edit_days' => (int) $data['daily_work_edit_days']];
        $organization->forceFill(['settings' => $settings])->save();
        AuditLogger::record('organizations', 'attendance_rules', $organization, $before, ['attendance' => $settings['attendance'], 'daily_work' => $settings['daily_work']]);

        return $this->organization();
    }

    public function employee(Employee $employee): JsonResponse
    {
        abort_unless(EmployeeAccess::mayView($employee), 404);

        return response()->json(['data' => $this->employeeState($employee)]);
    }

    /** Shift fields left empty fall back to the organization rule. */
    public function updateEmployee(Request $request, Employee $employee): JsonResponse
    {
        abort_unless(EmployeeAccess::mayView($employee), 404);
        abort_unless(EmployeeAccess::reaches($employee, 'attendance.manage'), 403, __('auth.forbidden'));
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            ...$this->shiftRules(false),
            'biometric_pin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z]+$/', Rule::unique('employees', 'biometric_pin')->where('organization_id', $orgId)->ignore($employee->id)],
        ]);

        $before = ['shift' => $employee->shift?->only(['work_start', 'work_end', 'late_grace_minutes', 'half_day_below_hours', 'weekly_offs']), 'biometric_pin' => $employee->biometric_pin];
        $shiftData = collect($data)->only(['work_start', 'work_end', 'late_grace_minutes', 'half_day_below_hours', 'weekly_offs'])->all();
        if (collect($shiftData)->filter(fn ($v) => $v !== null)->isEmpty()) {
            $employee->shift()->delete();
        } else {
            EmployeeShift::updateOrCreate(['employee_id' => $employee->id], $shiftData + ['updated_by' => $request->user()->id]);
        }
        if (array_key_exists('biometric_pin', $data)) {
            $employee->forceFill(['biometric_pin' => $data['biometric_pin']])->save();
        }
        $employee->unsetRelation('shift');
        $after = $this->employeeState($employee);
        AuditLogger::record('attendance', 'employee_settings', $employee, $before, ['shift' => $after['shift'], 'biometric_pin' => $after['biometric_pin']]);

        return response()->json(['data' => $after]);
    }

    /** @return array<string, mixed> */
    private function employeeState(Employee $employee): array
    {
        $shift = $employee->shift()->first();
        $effective = AttendanceRules::forEmployee($employee);

        return [
            'shift' => $shift?->only(['work_start', 'work_end', 'late_grace_minutes', 'half_day_below_hours', 'weekly_offs']),
            'effective' => [
                'work_start' => $effective->workStart, 'work_end' => $effective->workEnd, 'late_grace_minutes' => $effective->lateGraceMinutes,
                'half_day_below_hours' => $effective->halfDayBelowHours, 'weekly_offs' => $effective->weeklyOffs,
            ],
            'biometric_pin' => $employee->biometric_pin,
            'can_edit' => EmployeeAccess::reaches($employee, 'attendance.manage'),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function shiftRules(bool $required): array
    {
        $r = $required ? 'required' : 'nullable';

        return [
            'work_start' => [$r, 'date_format:H:i'],
            'work_end' => [$r, 'date_format:H:i'],
            'late_grace_minutes' => [$r, 'integer', 'min:0', 'max:240'],
            'half_day_below_hours' => [$r, 'numeric', 'min:0.5', 'max:12'],
            // An empty list is allowed (no weekly off), so "present" rather than "required".
            'weekly_offs' => [$required ? 'present' : 'nullable', 'array', 'max:6'],
            'weekly_offs.*' => ['integer', 'between:1,7', 'distinct'],
        ];
    }
}
