<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Department;
use App\Models\Employee;
use App\Support\Access\AccessContext;
use App\Support\Access\Reach;
use App\Support\Audit\AuditLogger;
use App\Support\Queries\PeopleQueries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    private const RELATIONS = ['institution', 'department', 'departments', 'reportingOfficer', 'user'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'institution_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PeopleQueries::employees()->with(self::RELATIONS)
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('employee_code', 'like', "%{$s}%")->orWhere('designation', 'like', "%{$s}%")))
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($f['institution_id'] ?? null, fn ($q, $id) => $q->where('institution_id', $id))
            ->when(array_key_exists('is_active', $f) && $f['is_active'] !== null, fn ($q) => $q->where('is_active', (bool) $f['is_active']))
            ->orderBy('name');

        return EmployeeResource::collection($query->paginate($f['per_page'] ?? 25)->withQueryString());
    }

    public function show(Employee $employee): EmployeeResource
    {
        abort_unless(PeopleQueries::employees()->whereKey($employee->id)->exists(), 404);

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function store(Request $request): EmployeeResource
    {
        $data = $this->validated($request);
        $this->assertPlacement('employees.create', $data);

        $employee = DB::transaction(function () use ($data, $request) {
            $employee = new Employee($this->attributes($data));
            $employee->forceFill(['created_by' => $request->user()->id])->save();
            $employee->departments()->sync($data['additional_department_ids'] ?? []);

            return $employee;
        });
        AuditLogger::record('employees', 'create', $employee, null, AuditLogger::snapshot($employee));

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function update(Request $request, Employee $employee): EmployeeResource
    {
        abort_unless(PeopleQueries::employees('employees.edit')->whereKey($employee->id)->exists(), 404);
        $data = $this->validated($request, $employee);
        if ((int) $data['department_id'] !== $employee->department_id || array_diff($data['additional_department_ids'] ?? [], $employee->departments()->pluck('departments.id')->all())) {
            $this->assertPlacement('employees.edit', $data);
        }

        $before = $employee->getAttributes();
        DB::transaction(function () use ($employee, $data, $request) {
            $employee->fill($this->attributes($data));
            $employee->forceFill(['updated_by' => $request->user()->id])->save();
            $employee->departments()->sync($data['additional_department_ids'] ?? []);
        });
        AuditLogger::recordChanges('employees', 'update', $employee, $before);

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function setActive(Request $request, Employee $employee): EmployeeResource
    {
        abort_unless(PeopleQueries::employees('employees.activate')->whereKey($employee->id)->exists(), 404);
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);

        $before = $employee->getAttributes();
        $employee->forceFill(['is_active' => (bool) $data['is_active'], 'updated_by' => $request->user()->id])->save();
        AuditLogger::recordChanges('employees', $data['is_active'] ? 'activate' : 'deactivate', $employee, $before, $data['reason'] ?? null);

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    /** @param  array<string, mixed>  $data */
    private function assertPlacement(string $permission, array $data): void
    {
        foreach ([$data['department_id'], ...($data['additional_department_ids'] ?? [])] as $deptId) {
            Reach::assertDepartment($permission, (int) $deptId);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        // The institution follows the primary department (null for central departments).
        $department = Department::query()->findOrFail($data['department_id']);

        return [
            ...collect($data)->only(['user_id', 'employee_code', 'name', 'designation', 'phone', 'email', 'department_id', 'reporting_officer_id', 'joining_date'])->all(),
            'institution_id' => $department->institution_id,
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Employee $employee = null): array
    {
        $orgId = AccessContext::current()->organizationId();

        return $request->validate([
            'employee_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\/-]+$/', Rule::unique('employees')->where('organization_id', $orgId)->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'additional_department_ids' => ['nullable', 'array'],
            'additional_department_ids.*' => ['integer', 'distinct', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'reporting_officer_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('organization_id', $orgId), Rule::notIn(array_filter([$employee?->id]))],
            // Link to a login account that holds a role here and is not linked to another employee.
            'user_id' => ['nullable', 'integer', Rule::exists('role_assignments', 'user_id')->where('organization_id', $orgId),
                Rule::unique('employees', 'user_id')->where('organization_id', $orgId)->ignore($employee?->id)],
            'joining_date' => ['nullable', 'date'],
        ]);
    }
}
