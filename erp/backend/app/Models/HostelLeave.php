<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows the hostel. */
class HostelLeave extends Model
{
    protected $table = 'hostel_leaves';

    protected $fillable = ['resident_id', 'leave_date', 'expected_return_date', 'actual_return_date', 'reason', 'escort_name', 'approved_by'];

    protected function casts(): array
    {
        return ['leave_date' => 'date:Y-m-d', 'expected_return_date' => 'date:Y-m-d', 'actual_return_date' => 'date:Y-m-d'];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(HostelResident::class, 'resident_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
