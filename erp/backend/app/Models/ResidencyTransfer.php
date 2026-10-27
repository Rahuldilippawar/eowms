<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows its room / parent. */
class ResidencyTransfer extends Model
{
    protected $table = 'residency_transfers';

    protected $fillable = ['occupant_id', 'from_location_id', 'to_location_id', 'transfer_date', 'reason', 'transferred_by'];

    protected function casts(): array
    {
        return ['transfer_date' => 'date:Y-m-d'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLocation::class, 'from_location_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLocation::class, 'to_location_id');
    }

    public function occupant(): BelongsTo
    {
        return $this->belongsTo(ResidencyOccupant::class, 'occupant_id');
    }
}
