<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Access\AccessContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as seen by administrators of the current organization: only their
 * roles in this organization are shown, never their other memberships.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $orgId = AccessContext::current()?->organizationId();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'is_super_admin' => (bool) $this->is_super_admin,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'role_assignments' => $this->whenLoaded('roleAssignments', fn () => $this->roleAssignments
                ->where('organization_id', $orgId)
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'role' => ['id' => $a->role->id, 'name' => $a->role->name, 'key' => $a->role->key],
                    'institutions' => $a->institutions->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])->values(),
                    'departments' => $a->departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
                ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
