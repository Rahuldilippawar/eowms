<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\LeaveRequestResource;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AttendanceRules;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Support\Access\LeaveAccess;
use App\Support\Audit\AuditLogger;
use App\Support\Queries\PeopleQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Self-service for the signed-in user's own employee record in the current
 * organization: no permission is needed beyond having that record.
 */
class MeController extends Controller
{
    public function employee(): JsonResponse
    {
        $employee = PeopleQueries::myEmployee();

        return response()->json(['data' => $employee ? (new EmployeeResource($employee->load(['institution', 'department', 'reportingOfficer'])))->resolve() : null]);
    }

    public function attendance(Request $request): AnonymousResourceCollection
    {
        $employee = $this->employeeOrFail();
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $data['month'] ?? AttendanceRules::for($employee->organization)->now()->format('Y-m');

        $records = AttendanceRecord::query()->where('employee_id', $employee->id)
            ->where('work_date', '>=', "{$month}-01")->where('work_date', '<=', date('Y-m-t', strtotime("{$month}-01")))
            ->orderByDesc('work_date')->get();

        return AttendanceRecordResource::collection($records);
    }

    public function checkIn(AttendanceService $service): AttendanceRecordResource
    {
        $record = $service->checkIn($this->activeEmployeeOrFail());
        AuditLogger::record('attendance', 'check_in', $record);

        return new AttendanceRecordResource($record);
    }

    public function checkOut(AttendanceService $service): AttendanceRecordResource
    {
        $record = $service->checkOut($this->activeEmployeeOrFail());
        AuditLogger::record('attendance', 'check_out', $record);

        return new AttendanceRecordResource($record);
    }

    public function requestCorrection(Request $request): JsonResponse
    {
        $employee = $this->activeEmployeeOrFail();
        $today = AttendanceRules::for($employee->organization)->now()->toDateString();
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'requested_status' => ['required', Rule::in(['present', 'late', 'half_day', 'absent'])],
            'requested_check_in' => ['nullable', 'date_format:H:i'],
            'requested_check_out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $pending = AttendanceCorrection::query()->where(['employee_id' => $employee->id, 'work_date' => $data['work_date'], 'status' => 'pending'])->exists();
        if ($pending) {
            throw ValidationException::withMessages(['work_date' => [__('attendance.correction_pending')]]);
        }

        $correction = AttendanceCorrection::create([
            ...$data,
            'employee_id' => $employee->id,
            'status' => 'pending',
            'requested_by' => $request->user()->id,
        ]);
        AuditLogger::record('attendance', 'correction_requested', $correction, null, $data);

        return response()->json(['data' => ['id' => $correction->id, 'status' => 'pending']], 201);
    }

    public function corrections(): JsonResponse
    {
        $employee = $this->employeeOrFail();

        return response()->json(['data' => AttendanceCorrection::query()->where('employee_id', $employee->id)->orderByDesc('id')->limit(50)
            ->get(['id', 'work_date', 'requested_status', 'requested_check_in', 'requested_check_out', 'reason', 'status', 'decision_remarks', 'decided_at'])]);
    }

    public function leave(): AnonymousResourceCollection
    {
        $employee = $this->employeeOrFail();

        return LeaveRequestResource::collection(LeaveRequest::query()->with(['leaveType', 'reviewer'])
            ->where('employee_id', $employee->id)->orderByDesc('start_date')->limit(100)->get());
    }

    public function applyLeave(Request $request): LeaveRequestResource
    {
        $employee = $this->activeEmployeeOrFail();
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('organization_id', $employee->organization_id)->where('is_active', true)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);
        LeaveService::assertNoOverlap($employee, $data['start_date'], $data['end_date']);

        $path = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            // Private disk (storage/app/private): only served through attachment(), never by URL.
            $path = $file->storeAs("leave/{$employee->organization_id}", Str::uuid().'.'.$file->guessExtension(), 'local');
        }

        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $data['leave_type_id'],
            'institution_id' => $employee->institution_id,
            'department_id' => $employee->department_id,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'days' => LeaveService::days($data['start_date'], $data['end_date']),
            'reason' => $data['reason'],
            'attachment_path' => $path,
            'attachment_name' => $path ? mb_substr($request->file('attachment')->getClientOriginalName(), 0, 255) : null,
            'status' => 'requested',
            'reviewer_employee_id' => $employee->reporting_officer_id,
            'requested_by' => $request->user()->id,
        ]);
        AuditLogger::record('leave', 'request', $leave, null, collect($leave->getAttributes())->only(['leave_type_id', 'start_date', 'end_date', 'days'])->all());

        return new LeaveRequestResource($leave->load(['leaveType', 'reviewer']));
    }

    public function cancelLeave(LeaveRequest $leave): LeaveRequestResource
    {
        $employee = $this->employeeOrFail();
        abort_unless($leave->employee_id === $employee->id, 404);
        if ($leave->status !== 'requested') {
            throw ValidationException::withMessages(['status' => [__('leave.cancel_only_requested')]]);
        }
        $before = $leave->getAttributes();
        $leave->forceFill(['status' => 'cancelled'])->save();
        AuditLogger::recordChanges('leave', 'cancel', $leave, $before);

        return new LeaveRequestResource($leave->load(['leaveType', 'reviewer']));
    }

    public function leaveTypes(): JsonResponse
    {
        return response()->json(['data' => LeaveType::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'days_per_year', 'is_paid'])]);
    }

    public function attachment(LeaveRequest $leave): StreamedResponse
    {
        abort_unless(LeaveAccess::mayView($leave), 404);
        abort_if($leave->attachment_path === null || ! Storage::disk('local')->exists($leave->attachment_path), 404);

        return Storage::disk('local')->download($leave->attachment_path, $leave->attachment_name ?? 'attachment');
    }

    private function employeeOrFail(): Employee
    {
        $employee = PeopleQueries::myEmployee();
        abort_if($employee === null, 404, __('attendance.no_employee'));

        return $employee;
    }

    private function activeEmployeeOrFail(): Employee
    {
        $employee = $this->employeeOrFail();
        abort_unless($employee->is_active, 403, __('auth.inactive'));

        return $employee;
    }
}
