<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows the hostel. */
class HostelHealthIncident extends Model
{
    protected $table = 'hostel_health_incidents';

    protected $fillable = ['resident_id', 'incident_date', 'description', 'action_taken', 'reported_by'];

    protected function casts(): array
    {
        return ['incident_date' => 'date:Y-m-d'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
