<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'assignments_count' => $this->whenCounted('assignments'),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions
                ->map(fn ($p) => ['key' => $p->key, 'scope' => $p->pivot->scope])->sortBy('key')->values()),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
