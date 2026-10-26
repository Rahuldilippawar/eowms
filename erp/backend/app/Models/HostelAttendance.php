<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows the hostel. */
class HostelAttendance extends Model
{
    protected $table = 'hostel_attendance';

    protected $fillable = ['hostel_id', 'resident_id', 'attendance_date', 'session', 'status', 'remark', 'marked_by'];

    protected function casts(): array
    {
        return ['attendance_date' => 'date:Y-m-d'];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(HostelResident::class, 'resident_id');
    }
}
