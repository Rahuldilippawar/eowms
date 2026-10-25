<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InstitutionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\InstitutionResource;
use App\Models\Institution;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Queries\ScopedQueries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class InstitutionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(InstitutionType::class)],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ScopedQueries::institutions()
            ->withCount('departments')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name');

        return InstitutionResource::collection($query->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function show(Institution $institution): InstitutionResource
    {
        // Bound within the current organization already; this enforces the user's scope.
        abort_unless(ScopedQueries::institutions()->whereKey($institution->id)->exists(), 404);

        return new InstitutionResource($institution->loadCount('departments'));
    }

    public function store(Request $request): InstitutionResource
    {
        // A new institution widens the organization: organization-wide scope only.
        abort_unless(AccessContext::current()->scope('institutions.create')?->isOrganizationWide(), 403, __('access.create_needs_org_scope'));

        $institution = new Institution($this->validated($request));
        $institution->created_by = $request->user()->id;
        $institution->save();

        AuditLogger::record('institutions', 'create', $institution, null, AuditLogger::snapshot($institution));

        return new InstitutionResource($institution->loadCount('departments'));
    }

    public function update(Request $request, Institution $institution): InstitutionResource
    {
        abort_unless(ScopedQueries::institutions('institutions.edit')->whereKey($institution->id)->exists(), 404);

        $before = $institution->getAttributes();
        $institution->fill($this->validated($request, $institution));
        $institution->updated_by = $request->user()->id;
        $institution->save();

        AuditLogger::recordChanges('institutions', 'update', $institution, $before);

        return new InstitutionResource($institution->loadCount('departments'));
    }

    public function setActive(Request $request, Institution $institution): InstitutionResource
    {
        abort_unless(ScopedQueries::institutions('institutions.activate')->whereKey($institution->id)->exists(), 404);
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);

        $before = $institution->getAttributes();
        $institution->forceFill(['is_active' => (bool) $data['is_active'], 'updated_by' => $request->user()->id])->save();
        AuditLogger::recordChanges('institutions', $data['is_active'] ? 'activate' : 'deactivate', $institution, $before, $data['reason'] ?? null);

        return new InstitutionResource($institution->loadCount('departments'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Institution $institution = null): array
    {
        $orgId = AccessContext::current()->organizationId();

        return $request->validate([
            'type' => ['required', Rule::enum(InstitutionType::class)],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('institutions', 'code')->where('organization_id', $orgId)->ignore($institution?->id),
            ],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'head_user_id' => ['nullable', 'integer', Rule::exists('role_assignments', 'user_id')->where('organization_id', $orgId)],
        ]);
    }
}
