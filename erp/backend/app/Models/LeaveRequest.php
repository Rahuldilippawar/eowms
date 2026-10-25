<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'employee_id', 'leave_type_id', 'institution_id', 'department_id', 'start_date', 'end_date',
        'days', 'reason', 'attachment_path', 'attachment_name', 'status', 'reviewer_employee_id', 'requested_by',
        'decided_by', 'decided_at', 'decision_remarks',
    ];

    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'days' => 'decimal:1', 'decided_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_employee_id');
    }
}
