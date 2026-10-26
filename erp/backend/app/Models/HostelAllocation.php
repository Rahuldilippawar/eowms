<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows the hostel. */
class HostelAllocation extends Model
{
    protected $table = 'hostel_allocations';

    protected $fillable = ['room_id', 'resident_id', 'bed_label', 'allocated_on', 'vacated_on', 'created_by'];

    protected function casts(): array
    {
        return ['allocated_on' => 'date:Y-m-d', 'vacated_on' => 'date:Y-m-d'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'room_id');
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(HostelResident::class, 'resident_id');
    }
}
