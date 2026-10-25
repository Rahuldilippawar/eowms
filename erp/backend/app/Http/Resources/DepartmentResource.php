<?php

namespace App\Http\Resources;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Department */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'institution_id' => $this->institution_id,
            'institution' => $this->whenLoaded('institution', fn () => $this->institution ? [
                'id' => $this->institution->id,
                'name' => $this->institution->name,
                'code' => $this->institution->code,
            ] : null),
            'is_central' => $this->isCentral(),
            'name' => $this->name,
            'code' => $this->code,
            'category' => $this->category,
            'head_user_id' => $this->head_user_id,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
