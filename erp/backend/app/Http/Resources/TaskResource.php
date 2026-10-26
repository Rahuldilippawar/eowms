<?php

namespace App\Http\Resources;

use App\Models\Task;
use App\Models\TaskUpdate;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Task */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = $request->attributes->get('org_today');

        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'due_date' => $this->due_date->toDateString(),
            'status' => $this->status,
            'is_overdue' => $today !== null && $this->isOpen() && $this->due_date->toDateString() < $today,
            'assignee' => $this->whenLoaded('assignee', fn () => ['id' => $this->assignee->id, 'name' => $this->assignee->name, 'employee_code' => $this->assignee->employee_code]),
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'assigned_by' => $this->whenLoaded('assigner', fn () => ['id' => $this->assigner->id, 'name' => $this->assigner->name]),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cost_incurred' => $this->cost_incurred === null ? null : (float) $this->cost_incurred,
            'created_at' => $this->created_at?->toIso8601String(),
            'actions' => $request->user() ? TaskService::actionsFor($request->user(), $this->resource) : [],
            'updates' => $this->whenLoaded('updates', fn () => $this->updates->map(fn (TaskUpdate $u) => [
                'id' => $u->id, 'type' => $u->type, 'from_status' => $u->from_status, 'to_status' => $u->to_status, 'body' => $u->body,
                'user' => ['id' => $u->user->id, 'name' => $u->user->name], 'created_at' => $u->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
