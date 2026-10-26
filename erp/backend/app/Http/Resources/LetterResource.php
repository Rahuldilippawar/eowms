<?php

namespace App\Http\Resources;

use App\Models\Letter;
use App\Models\LetterAttachment;
use App\Models\LetterInwardNumber;
use App\Models\LetterMovement;
use App\Models\LetterSignature;
use App\Services\Correspondence\ApprovalEngine;
use App\Services\Correspondence\LetterService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Letter */
class LetterResource extends JsonResource
{
    /** Full detail (register, attachments, signatures, actions) only on the single-file view. */
    public bool $detail = false;

    public function detailed(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $ref = fn ($u) => $u ? ['id' => $u->id, 'name' => $u->name] : null;

        return [
            'id' => $this->id,
            'file_number' => $this->file_number,
            'outward_no' => $this->outward_no,
            'reference_no' => $this->reference_no,
            'subject' => $this->subject,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null),
            'priority' => $this->priority,
            'status' => $this->status,
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name, 'code' => $this->department->code] : null),
            'creator' => $this->whenLoaded('creator', fn () => $ref($this->creator)),
            'holder' => $this->whenLoaded('holder', fn () => $ref($this->holder)),
            'holder_since' => $this->holder_since?->toIso8601String(),
            'holder_seen_at' => $this->holder_seen_at?->toIso8601String(),
            'workflow' => $this->whenLoaded('workflow', fn () => $this->workflow ? ['id' => $this->workflow->id, 'name' => $this->workflow->name] : null),
            'current_step_order' => $this->current_step_order,
            'is_budget_expense' => $this->is_budget_expense,
            'budget_category' => $this->budget_category,
            'requested_amount' => $this->requested_amount === null ? null : (float) $this->requested_amount,
            'approved_amount' => $this->approved_amount === null ? null : (float) $this->approved_amount,
            'dispatch_mode' => $this->dispatch_mode,
            'dispatch_tracking_no' => $this->dispatch_tracking_no,
            'dispatch_date' => $this->dispatch_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            $this->mergeWhen($this->detail, fn () => [
                'description' => $this->description,
                'letter_category_id' => $this->letter_category_id,
                'approval_workflow_id' => $this->approval_workflow_id,
                'actions' => $user ? app(LetterService::class)->actionsFor($user, $this->resource) : [],
                'steps' => $this->workflow ? $this->workflow->steps->map(fn ($s) => [
                    'step_order' => $s->step_order,
                    'name' => $s->name,
                    'approver' => $ref(app(ApprovalEngine::class)->approver($s, $this->department_id)),
                ])->values() : [],
                'movements' => $this->movements->map(fn (LetterMovement $m) => [
                    'id' => $m->id, 'type' => $m->type, 'from' => $ref($m->fromUser), 'to' => $ref($m->toUser), 'step_order' => $m->step_order,
                    'remark' => $m->remark, 'created_at' => $m->created_at?->toIso8601String(),
                ])->values(),
                'attachments' => $this->attachments->map(fn (LetterAttachment $a) => [
                    'id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size, 'created_at' => $a->created_at?->toIso8601String(),
                ])->values(),
                'inward_numbers' => $this->inwardNumbers->map(fn (LetterInwardNumber $n) => [
                    'department' => $n->department?->name, 'inward_no' => $n->inward_no, 'created_at' => $n->created_at?->toIso8601String(),
                ])->values(),
                'signatures' => $this->signatures->map(fn (LetterSignature $s) => [
                    'signer_name' => $s->signer_name, 'signer_designation' => $s->signer_designation, 'method' => $s->method,
                    'verification_code' => $s->verification_code, 'signed_at' => $s->signed_at->toIso8601String(),
                    'content_hash' => $s->content_hash, 'intact' => LetterService::intact($s->setRelation('letter', $this->resource)),
                ])->values(),
            ]),
        ];
    }
}
