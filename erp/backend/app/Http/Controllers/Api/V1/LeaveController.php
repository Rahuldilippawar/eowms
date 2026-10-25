<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use App\Services\Notifier;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Queries\PeopleQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LeaveController extends Controller
{
    /**
     * Requests the user may decide: those where they are the recorded
     * reporting officer, plus those reachable through leave.approve.
     */
    public function approvals(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(['requested', 'approved', 'rejected', 'cancelled'])]]);
        $user = $request->user();
        $context = AccessContext::current();
        $myEmployeeIds = Employee::query()->where('user_id', $user->id)->pluck('id');

        $query = LeaveRequest::query()->with(['employee', 'leaveType', 'reviewer'])
            ->where('status', $f['status'] ?? 'requested')
            ->whereNotIn('employee_id', $myEmployeeIds)
            ->where(function ($w) use ($context, $myEmployeeIds) {
                $w->whereIn('reviewer_employee_id', $myEmployeeIds ?: [0]);
                if ($context->can('leave.approve')) {
                    $w->orWhereIn('employee_id', PeopleQueries::employeeIds('leave.approve'));
                }
            })
            ->orderBy('start_date');

        return LeaveRequestResource::collection($query->limit(200)->get());
    }

    /** All requests in the user's leave.view scope (history / reports). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['requested', 'approved', 'rejected', 'cancelled'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PeopleQueries::leave()->with(['employee', 'leaveType', 'reviewer'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('end_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('start_date', '<=', $d))
            ->orderByDesc('start_date');

        return LeaveRequestResource::collection($query->paginate($f['per_page'] ?? 25)->withQueryString());
    }

    public function decide(Request $request, LeaveRequest $leave, LeaveService $service): LeaveRequestResource
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'remarks' => ['nullable', 'string', 'max:500']]);
        $leave->load(['employee', 'reviewer']);
        // Not decidable = not visible as a decision for this user.
        abort_unless(LeaveService::mayDecide($request->user(), $leave), 404);

        $decided = $service->decide($leave, (bool) $data['approve'], $data['remarks'] ?? null, $request->user());
        $decided->load(['employee', 'leaveType']);
        Notifier::employee($decided->employee, 'leave.decided', [
            'leave_id' => $decided->id, 'status' => $decided->status, 'type' => $decided->leaveType->name,
            'start_date' => $decided->start_date->toDateString(), 'end_date' => $decided->end_date->toDateString(),
            'by' => $request->user()->name, 'note' => $decided->decision_remarks,
        ], '/my/leave');

        return new LeaveRequestResource($decided->load(['employee', 'leaveType', 'reviewer']));
    }

    public function types(): JsonResponse
    {
        return response()->json(['data' => LeaveType::query()->orderBy('name')->get(['id', 'code', 'name', 'days_per_year', 'is_paid', 'is_active'])]);
    }

    public function storeType(Request $request): JsonResponse
    {
        $type = LeaveType::create($this->validatedType($request));
        AuditLogger::record('leave', 'type_create', $type, null, AuditLogger::snapshot($type));

        return response()->json(['data' => $type], 201);
    }

    public function updateType(Request $request, LeaveType $leaveType): JsonResponse
    {
        $before = $leaveType->getAttributes();
        $leaveType->fill($this->validatedType($request, $leaveType))->save();
        AuditLogger::recordChanges('leave', 'type_update', $leaveType, $before);

        return response()->json(['data' => $leaveType]);
    }

    /** @return array<string, mixed> */
    private function validatedType(Request $request, ?LeaveType $type = null): array
    {
        abort_unless(AccessContext::current()->scope('leave.manage_types')?->isOrganizationWide(), 403, __('access.create_needs_org_scope'));
        $orgId = AccessContext::current()->organizationId();

        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('leave_types')->where('organization_id', $orgId)->ignore($type?->id)],
            'name' => ['required', 'string', 'max:255'],
            'days_per_year' => ['required', 'numeric', 'min:0', 'max:366'],
            'is_paid' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
