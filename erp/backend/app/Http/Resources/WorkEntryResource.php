<?php

namespace App\Http\Resources;

use App\Models\WorkEntry;
use App\Services\WorkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkEntry */
class WorkEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => ['id' => $this->employee->id, 'name' => $this->employee->name, 'employee_code' => $this->employee->employee_code]),
            'work_date' => $this->work_date->toDateString(),
            'title' => $this->title,
            'description' => $this->description,
            'hours' => $this->hours === null ? null : (float) $this->hours,
            'status' => $this->status,
            'task' => $this->whenLoaded('task', fn () => $this->task ? ['id' => $this->task->id, 'code' => $this->task->code, 'title' => $this->task->title] : null),
            'requested_by' => $this->requested_by,
            'estimated_cost' => $this->estimated_cost === null ? null : (float) $this->estimated_cost,
            'actual_cost' => $this->actual_cost === null ? null : (float) $this->actual_cost,
            'team' => $this->whenLoaded('team', fn () => $this->team->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'employee_code' => $e->employee_code])->values()),
            'has_before_photo' => $this->before_photo_path !== null,
            'has_after_photo' => $this->after_photo_path !== null,
            'is_imported' => (bool) $this->is_imported,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'review_remarks' => $this->review_remarks,
            'custom' => $this->custom ?? (object) [],
            'can_edit' => app(WorkService::class)->editable($this->resource, $request->user()),
        ];
    }
}
