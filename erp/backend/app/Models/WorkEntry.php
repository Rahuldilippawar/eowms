<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkEntry extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['completed', 'in_progress', 'pending'];

    protected $fillable = [
        'organization_id', 'employee_id', 'institution_id', 'department_id', 'work_date', 'title', 'description',
        'hours', 'status', 'task_id', 'reviewed_by', 'reviewed_at', 'review_remarks', 'created_by', 'updated_by', 'custom',
    ];

    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'hours' => 'decimal:2', 'reviewed_at' => 'datetime', 'custom' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
