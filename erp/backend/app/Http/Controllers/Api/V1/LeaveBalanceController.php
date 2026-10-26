<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveType;
use App\Services\AttendanceRules;
use App\Services\LeaveBalances;
use App\Support\Access\AccessContext;
use App\Support\Access\EmployeeAccess;
use App\Support\Audit\AuditLogger;
use App\Support\Queries\PeopleQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Leave balances of the employees within `leave.view`; allocations need `leave.balances`. */
class LeaveBalanceController extends Controller
{
    public function __construct(private readonly LeaveBalances $balances) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $year = (int) ($f['year'] ?? AttendanceRules::for(AccessContext::current()->organization())->now()->year);
        $page = PeopleQueries::employees('leave.view')->where('employees.is_active', true)
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('employees.department_id', $id))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('employees.name', 'like', '%'.addcslashes($s, '%_\\').'%')->orWhere('employees.employee_code', $s)))
            ->orderBy('employees.name')->paginate(25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Employee $e) => [
                'employee' => ['id' => $e->id, 'name' => $e->name, 'employee_code' => $e->employee_code],
                'balances' => $this->balances->forEmployee($e, $year),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'year' => $year,
            'can_allocate' => AccessContext::current()->can('leave.balances'),
        ]);
    }

    /** Set the days given for one employee, type and year (overrides the type's days per year). */
    public function allocate(Request $request): JsonResponse
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('organization_id', $orgId)],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'days' => ['required', 'numeric', 'min:0', 'max:366', 'multiple_of:0.5'],
        ]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        abort_unless(EmployeeAccess::reaches($employee, 'leave.balances'), 404);

        $allocation = LeaveAllocation::query()->firstOrNew(['employee_id' => $employee->id, 'leave_type_id' => $data['leave_type_id'], 'year' => $data['year']]);
        $before = $allocation->exists ? $allocation->getAttributes() : [];
        $allocation->fill(['days' => $data['days'], 'updated_by' => $request->user()->id])->save();
        AuditLogger::recordChanges('leave', 'allocate', $allocation, $before);

        return response()->json(['data' => ['leave_type' => ['id' => (int) $data['leave_type_id']]] + $this->balances->balance($employee, LeaveType::query()->findOrFail($data['leave_type_id']), (int) $data['year'])]);
    }
}
