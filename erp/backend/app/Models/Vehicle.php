<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use BelongsToOrganization;

    public const FUEL_TYPES = ['petrol', 'diesel', 'cng', 'electric', 'other'];

    /** Documents every vehicle renews; each date is a renewal item (erp/docs/VEHICLES.md). */
    public const RENEWAL_FIELDS = ['permit', 'puc', 'insurance', 'fitness', 'road_tax', 'green_tax', 'mv_tax'];

    protected $fillable = [
        'organization_id', 'institution_id', 'department_id', 'vehicle_number', 'vehicle_type', 'model', 'registration_date', 'chassis_no',
        'engine_no', 'vehicle_class', 'passenger_capacity', 'driver_user_id', 'fuel_type', 'current_odometer', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['registration_date' => 'date:Y-m-d', 'current_odometer' => 'decimal:1', 'is_active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(VehicleTrip::class);
    }

    public function fuelLogs(): HasMany
    {
        return $this->hasMany(VehicleFuelLog::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(VehicleService::class);
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(RenewalItem::class);
    }
}
