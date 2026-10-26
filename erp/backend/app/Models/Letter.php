<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Letter extends Model
{
    use BelongsToOrganization;

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** States in which a file is with somebody. */
    public const MOVING = ['in_progress', 'returned'];

    public const DISPATCH_MODES = ['post', 'courier', 'speed_post', 'email', 'hand', 'other'];

    public const BUDGET_CATEGORIES = ['purchase', 'maintenance', 'fuel', 'other'];

    protected $fillable = [
        'organization_id', 'institution_id', 'department_id', 'file_number', 'outward_no', 'reference_no', 'subject', 'description',
        'letter_category_id', 'priority', 'status', 'approval_workflow_id', 'current_step_order', 'current_holder_id', 'holder_since',
        'holder_seen_at', 'is_budget_expense', 'budget_category', 'requested_amount', 'approved_amount', 'dispatch_mode',
        'dispatch_tracking_no', 'dispatch_date', 'submitted_at', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'holder_since' => 'datetime', 'submitted_at' => 'datetime', 'holder_seen_at' => 'datetime', 'completed_at' => 'datetime', 'dispatch_date' => 'date:Y-m-d',
            'is_budget_expense' => 'boolean', 'requested_amount' => 'decimal:2', 'approved_amount' => 'decimal:2',
            'current_step_order' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_holder_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LetterCategory::class, 'letter_category_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(LetterMovement::class)->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LetterAttachment::class)->orderBy('id');
    }

    public function inwardNumbers(): HasMany
    {
        return $this->hasMany(LetterInwardNumber::class)->orderBy('id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(LetterSignature::class)->orderBy('id');
    }

    public function isMoving(): bool
    {
        return in_array($this->status, self::MOVING, true);
    }
}
