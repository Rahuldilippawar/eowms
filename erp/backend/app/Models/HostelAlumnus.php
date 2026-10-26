<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows the hostel. */
class HostelAlumnus extends Model
{
    protected $table = 'hostel_alumni';

    protected $fillable = ['resident_id', 'departure_date', 'departure_reason', 'current_status', 'contact_number', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['departure_date' => 'date:Y-m-d'];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(HostelResident::class, 'resident_id');
    }
}
