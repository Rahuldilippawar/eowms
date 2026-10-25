<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'employee_id', 'work_date', 'requested_status', 'requested_check_in',
        'requested_check_out', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_remarks',
    ];

    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'decided_at' => 'datetime', 'requested_status' => AttendanceStatus::class];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
