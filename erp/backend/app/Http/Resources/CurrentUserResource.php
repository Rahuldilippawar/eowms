<?php

namespace App\Http\Resources;

use App\Models\Hostel;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Access\AccessContext;
use App\Support\Access\Modules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user as the SPA sees it: profile, current organization, the
 * organizations they can switch to, and the permission keys they hold in the
 * current organization (the SPA uses these only to show/hide controls — the
 * API enforces every permission itself). Never includes credentials.
 *
 * @mixin User
 */
class CurrentUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $context = AccessContext::current();
        $isCurrentUser = $context !== null && $context->user()->is($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_super_admin' => (bool) $this->is_super_admin,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'current_organization' => $this->when($isCurrentUser, fn () => $context->organization() ? [
                'id' => $context->organization()->id,
                'name' => $context->organization()->name,
                'short_name' => $context->organization()->short_name,
            ] : null),
            'organizations' => $this->when($isCurrentUser, fn () => AccessContext::organizationsFor($this->resource)
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'short_name' => $o->short_name])->values()),
            'permissions' => $this->when($isCurrentUser, fn () => $context->permissionKeys()),
            'disabled_modules' => $this->when($isCurrentUser, fn () => Modules::disabled($context->organization())),
            // Drivers reach their own vehicle without the vehicles permissions.
            'drives_vehicle' => $this->when($isCurrentUser, fn () => $context->organization() !== null
                && Vehicle::query()->where('driver_user_id', $this->id)->where('is_active', true)->exists()),
            // Hostel staff (wardens) reach their own hostel without the hostels permissions.
            'hostel_staff' => $this->when($isCurrentUser, fn () => $context->organization() !== null
                && Hostel::query()->where('is_active', true)->whereHas('staff', fn ($q) => $q->where('user_id', $this->id))->exists()),
            'must_change_password' => (bool) $this->must_change_password,
        ];
    }
}
