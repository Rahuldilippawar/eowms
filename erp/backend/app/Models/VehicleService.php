<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A vehicle log entry (tenancy follows the vehicle). */
class VehicleService extends Model
{
    protected $table = 'vehicle_services';

    protected $fillable = ['vehicle_id', 'service_date', 'type', 'description', 'cost', 'odometer_reading', 'next_due_date', 'next_due_km', 'vendor', 'created_by'];

    protected function casts(): array
    {
        return ['service_date' => 'date:Y-m-d', 'next_due_date' => 'date:Y-m-d', 'cost' => 'decimal:2', 'odometer_reading' => 'decimal:1', 'next_due_km' => 'decimal:1'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
