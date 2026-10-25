<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\Institution;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Queries\ScopedQueries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'institution_id' => ['nullable', 'integer'],
            'central' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ScopedQueries::departments()
            ->with('institution')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($filters['institution_id'] ?? null, fn ($q, $id) => $q->where('institution_id', $id))
            ->when(($filters['central'] ?? null) === true, fn ($q) => $q->whereNull('institution_id'))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name');

        return DepartmentResource::collection($query->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function show(Department $department): DepartmentResource
    {
        abort_unless(ScopedQueries::departments()->whereKey($department->id)->exists(), 404);

        return new DepartmentResource($department->load('institution'));
    }

    public function store(Request $request): DepartmentResource
    {
        $data = $this->validated($request);
        $this->assertMayPlaceIn('departments.create', $data['institution_id'] ?? null);

        $department = new Department($data);
        $department->created_by = $request->user()->id;
        $department->save();

        AuditLogger::record('departments', 'create', $department, null, AuditLogger::snapshot($department));

        return new DepartmentResource($department->load('institution'));
    }

    public function update(Request $request, Department $department): DepartmentResource
    {
        abort_unless(ScopedQueries::departments('departments.edit')->whereKey($department->id)->exists(), 404);
        $data = $this->validated($request, $department);
        if (($data['institution_id'] ?? null) !== $department->institution_id) {
            $this->assertMayPlaceIn('departments.edit', $data['institution_id'] ?? null);
        }

        $before = $department->getAttributes();
        $department->fill($data);
        $department->updated_by = $request->user()->id;
        $department->save();

        AuditLogger::recordChanges('departments', 'update', $department, $before);

        return new DepartmentResource($department->load('institution'));
    }

    public function setActive(Request $request, Department $department): DepartmentResource
    {
        abort_unless(ScopedQueries::departments('departments.activate')->whereKey($department->id)->exists(), 404);
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);

        $before = $department->getAttributes();
        $department->forceFill(['is_active' => (bool) $data['is_active'], 'updated_by' => $request->user()->id])->save();
        AuditLogger::recordChanges('departments', $data['is_active'] ? 'activate' : 'deactivate', $department, $before, $data['reason'] ?? null);

        return new DepartmentResource($department->load('institution'));
    }

    /**
     * Organization-wide scope may place a department anywhere (or make it
     * central); institution scope only inside its own institutions.
     */
    private function assertMayPlaceIn(string $permission, ?int $institutionId): void
    {
        $scope = AccessContext::current()->scope($permission);
        abort_if($scope === null, 403, __('auth.forbidden'));

        $allowed = match ($scope->scope) {
            PermissionScope::Organization => true,
            PermissionScope::Institutions => $institutionId !== null && in_array($institutionId, $scope->institutionIds, true),
            default => false,
        };
        if (! $allowed) {
            throw ValidationException::withMessages(['institution_id' => [__('access.outside_scope')]]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Department $department = null): array
    {
        $orgId = AccessContext::current()->organizationId();
        $institutionId = $request->filled('institution_id') ? (int) $request->input('institution_id') : null;

        $data = $request->validate([
            'institution_id' => ['nullable', 'integer', Rule::exists('institutions', 'id')->where('organization_id', $orgId)],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('departments', 'code')->where('organization_id', $orgId)
                    ->where('institution_key', $institutionId ?? 0)->ignore($department?->id),
            ],
            'category' => ['nullable', 'string', 'max:50'],
            'head_user_id' => ['nullable', 'integer', Rule::exists('role_assignments', 'user_id')->where('organization_id', $orgId)],
        ]);
        $data['institution_id'] = $institutionId;

        // Guard against a stale institution id from another organization slipping through.
        if ($institutionId !== null && ! Institution::query()->whereKey($institutionId)->exists()) {
            throw ValidationException::withMessages(['institution_id' => [__('validation.exists', ['attribute' => 'institution'])]]);
        }

        return $data;
    }
}
