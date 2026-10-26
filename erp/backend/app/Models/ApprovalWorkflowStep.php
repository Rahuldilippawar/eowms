<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of a workflow (tenancy follows the workflow). */
class ApprovalWorkflowStep extends Model
{
    /** Head of the record's own department, head of a chosen department, or a named person. */
    public const TYPES = ['origin_department_head', 'department_head', 'user'];

    public $timestamps = false;

    protected $fillable = ['approval_workflow_id', 'step_order', 'name', 'approver_type', 'department_id', 'user_id'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
