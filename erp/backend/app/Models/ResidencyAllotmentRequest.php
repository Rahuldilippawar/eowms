<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidencyAllotmentRequest extends Model
{
    use BelongsToOrganization;

    protected $table = 'residency_allotment_requests';

    protected $fillable = ['organization_id', 'requested_by', 'preferred_building_id', 'family_size', 'reason', 'status', 'allotted_location_id', 'decided_by', 'decided_at', 'remarks'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'family_size' => 'integer'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'preferred_building_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLocation::class, 'allotted_location_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
