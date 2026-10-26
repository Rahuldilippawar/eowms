<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResidencyBill extends Model
{
    use BelongsToOrganization;

    protected $table = 'residency_bills';

    protected $fillable = ['organization_id', 'location_id', 'billing_month', 'room_rent', 'electricity_bill', 'water_bill', 'total_amount', 'billing_unit_count', 'created_by'];

    protected function casts(): array
    {
        return ['room_rent' => 'decimal:2', 'electricity_bill' => 'decimal:2', 'water_bill' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLocation::class, 'location_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ResidencyBillShare::class, 'bill_id');
    }
}
