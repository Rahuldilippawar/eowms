<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'name' => $this->name,
            'designation' => $this->designation,
            'phone' => $this->phone,
            'email' => $this->email,
            'institution' => $this->whenLoaded('institution', fn () => $this->institution ? ['id' => $this->institution->id, 'name' => $this->institution->name] : null),
            'department' => $this->whenLoaded('department', fn () => ['id' => $this->department->id, 'name' => $this->department->name]),
            'additional_departments' => $this->whenLoaded('departments', fn () => $this->departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()),
            'reporting_officer' => $this->whenLoaded('reportingOfficer', fn () => $this->reportingOfficer ? ['id' => $this->reportingOfficer->id, 'name' => $this->reportingOfficer->name] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'email' => $this->user->email] : null),
            'joining_date' => $this->joining_date?->toDateString(),
            'is_active' => $this->is_active,
        ];
    }
}
