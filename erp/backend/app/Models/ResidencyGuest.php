<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidencyGuest extends Model
{
    use BelongsToOrganization;

    protected $table = 'residency_guests';

    protected $fillable = ['organization_id', 'location_id', 'host_occupant_id', 'guest_name', 'guest_contact', 'relation', 'purpose', 'arrival_date', 'expected_departure_date', 'actual_departure_date', 'created_by'];

    protected function casts(): array
    {
        return ['arrival_date' => 'date:Y-m-d', 'expected_departure_date' => 'date:Y-m-d', 'actual_departure_date' => 'date:Y-m-d'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLocation::class, 'location_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(ResidencyOccupant::class, 'host_occupant_id');
    }
}
