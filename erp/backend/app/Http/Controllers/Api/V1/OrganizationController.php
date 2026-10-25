<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Support\Access\AccessContext;
use App\Support\Access\DefaultRoles;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Creating, listing all and (de)activating organizations is for super admins.
 * Organization admins see and edit only their current organization.
 */
class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $query = Organization::query()
            ->unless($user->is_super_admin, fn ($q) => $q->whereKey(AccessContext::current()->organizationId()))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->orderBy('name');

        return OrganizationResource::collection($query->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function show(Request $request, Organization $organization): OrganizationResource
    {
        $this->assertReachable($request, $organization, 'organizations.view');

        return new OrganizationResource($organization);
    }

    public function store(Request $request): OrganizationResource
    {
        abort_unless($request->user()->is_super_admin, 403, __('auth.forbidden'));

        $organization = DB::transaction(function () use ($request) {
            $organization = new Organization($this->validated($request));
            $organization->created_by = $request->user()->id;
            $organization->save();
            DefaultRoles::createFor($organization);

            return $organization;
        });

        AuditLogger::record('organizations', 'create', $organization, null, AuditLogger::snapshot($organization), organizationId: $organization->id);

        return new OrganizationResource($organization);
    }

    public function update(Request $request, Organization $organization): OrganizationResource
    {
        $this->assertReachable($request, $organization, 'organizations.edit');

        $before = $organization->getAttributes();
        $organization->fill($this->validated($request, $organization));
        $organization->updated_by = $request->user()->id;
        $organization->save();
        AuditLogger::recordChanges('organizations', 'update', $organization, $before);

        return new OrganizationResource($organization);
    }

    public function setActive(Request $request, Organization $organization): OrganizationResource
    {
        abort_unless($request->user()->is_super_admin, 403, __('auth.forbidden'));
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);

        $before = $organization->getAttributes();
        $organization->forceFill(['is_active' => (bool) $data['is_active'], 'updated_by' => $request->user()->id])->save();
        AuditLogger::recordChanges('organizations', $data['is_active'] ? 'activate' : 'deactivate', $organization, $before, $data['reason'] ?? null);

        return new OrganizationResource($organization);
    }

    private function assertReachable(Request $request, Organization $organization, string $permission): void
    {
        if ($request->user()->is_super_admin) {
            return;
        }
        $context = AccessContext::current();
        // Another organization is reported as not found; the own one needs the permission org-wide.
        abort_unless($context->organizationId() === $organization->id, 404);
        abort_unless($context->scope($permission)?->isOrganizationWide(), 403, __('auth.forbidden'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Organization $organization = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('organizations', 'code')->ignore($organization?->id)],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'financial_year_start_month' => ['required', 'integer', 'between:1,12'],
        ]);
    }
}
