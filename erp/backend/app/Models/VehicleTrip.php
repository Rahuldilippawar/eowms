<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A vehicle log entry (tenancy follows the vehicle). */
class VehicleTrip extends Model
{
    protected $table = 'vehicle_trips';

    protected $fillable = ['vehicle_id', 'trip_date', 'from_location', 'to_location', 'start_time', 'end_time', 'start_odometer', 'end_odometer', 'distance_km', 'purpose', 'driver_user_id', 'created_by'];

    protected function casts(): array
    {
        return ['trip_date' => 'date:Y-m-d', 'start_odometer' => 'decimal:1', 'end_odometer' => 'decimal:1', 'distance_km' => 'decimal:1'];
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
