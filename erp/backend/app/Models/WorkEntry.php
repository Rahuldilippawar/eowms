<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkEntry extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['completed', 'in_progress', 'pending'];

    protected $fillable = [
        'organization_id', 'employee_id', 'institution_id', 'department_id', 'work_date', 'title', 'description',
        'hours', 'status', 'task_id', 'reviewed_by', 'reviewed_at', 'review_remarks', 'created_by', 'updated_by', 'custom',
        'requested_by', 'estimated_cost', 'actual_cost', 'is_imported',
    ];

    public const PHOTO_KINDS = ['before', 'after'];

    /** Most colleagues one entry can name. */
    public const MAX_TEAM = 20;

    public const MAX_MATERIALS = 20;

    protected $hidden = ['before_photo_path', 'after_photo_path'];

    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'hours' => 'decimal:2', 'reviewed_at' => 'datetime', 'custom' => 'array',
            'estimated_cost' => 'decimal:2', 'actual_cost' => 'decimal:2', 'is_imported' => 'boolean'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** Colleagues who did the work together with the employee. */
    public function team(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'work_entry_team')->orderBy('name');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(WorkEntryMaterial::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
