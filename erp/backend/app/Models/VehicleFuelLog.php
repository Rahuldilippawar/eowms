<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A vehicle log entry (tenancy follows the vehicle). */
class VehicleFuelLog extends Model
{
    protected $table = 'vehicle_fuel_logs';

    protected $fillable = ['vehicle_id', 'fill_date', 'odometer_reading', 'liters', 'cost', 'fuel_station', 'created_by'];

    protected function casts(): array
    {
        return ['fill_date' => 'date:Y-m-d', 'odometer_reading' => 'decimal:1', 'liters' => 'decimal:2', 'cost' => 'decimal:2'];
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
