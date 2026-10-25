<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use BelongsToOrganization;

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** States in which the task is still being worked on. */
    public const OPEN_STATES = ['open', 'in_progress', 'submitted'];

    protected $fillable = [
        'organization_id', 'code', 'title', 'description', 'priority', 'due_date', 'assignee_employee_id',
        'institution_id', 'department_id', 'assigned_by', 'status', 'submitted_at', 'completed_at', 'completed_by',
        'cancelled_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'submitted_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TaskUpdate::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATES, true);
    }
}
