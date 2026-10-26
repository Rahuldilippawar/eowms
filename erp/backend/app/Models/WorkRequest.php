<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkRequest extends Model
{
    use BelongsToOrganization;

    public const CATEGORIES = ['general', 'it', 'maintenance', 'housekeeping', 'other'];

    public const IT_SUB_CATEGORIES = ['internet_network', 'software', 'new_hardware', 'account_access', 'printer_scanner', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['pending', 'in_progress', 'completed', 'closed', 'cancelled'];

    public const OPEN = ['pending', 'in_progress'];

    /** Hours to finish by priority, as in the old app: urgent 8 h, high 1 day, normal 3 days, low 7 days. */
    public const SLA_HOURS = ['urgent' => 8, 'high' => 24, 'normal' => 72, 'low' => 168];

    /** A finished request can be reopened by its requester within this many days. */
    public const REOPEN_DAYS = 7;

    protected $fillable = [
        'organization_id', 'request_code', 'institution_id', 'department_id', 'category', 'sub_category', 'title', 'location', 'priority', 'due_at',
        'source', 'requester_name', 'requester_contact', 'requested_by_user_id', 'requesting_department_id', 'assigned_to_user_id',
        'work_description', 'cost_incurred', 'completion_remarks', 'status', 'completed_by', 'completed_at', 'escalation_level', 'escalated_at',
        'rating', 'feedback', 'closed_at', 'cancelled_at', 'reopened_count',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime', 'completed_at' => 'datetime', 'escalated_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'cost_incurred' => 'decimal:2', 'escalation_level' => 'integer', 'rating' => 'integer', 'reopened_count' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requestingDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'requesting_department_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(WorkRequestAction::class)->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WorkRequestComment::class)->orderBy('id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(WorkRequestView::class)->orderBy('first_viewed_at');
    }

    public static function slaHours(string $priority): int
    {
        return self::SLA_HOURS[$priority] ?? self::SLA_HOURS['normal'];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
