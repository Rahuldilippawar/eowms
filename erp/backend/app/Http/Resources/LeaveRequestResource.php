<?php

namespace App\Http\Resources;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveRequest */
class LeaveRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => ['id' => $this->employee->id, 'name' => $this->employee->name, 'employee_code' => $this->employee->employee_code]),
            'leave_type' => $this->whenLoaded('leaveType', fn () => ['id' => $this->leaveType->id, 'name' => $this->leaveType->name, 'code' => $this->leaveType->code]),
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'days' => (float) $this->days,
            'reason' => $this->reason,
            'has_attachment' => $this->attachment_path !== null,
            'attachment_name' => $this->attachment_name,
            'status' => $this->status,
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_remarks' => $this->decision_remarks,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
