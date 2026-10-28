<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItAsset extends Model
{
    use BelongsToOrganization;

    public const TYPES = ['computer', 'monitor', 'keyboard', 'mouse', 'cpu', 'ups', 'smartboard', 'projector', 'printer', 'other'];

    public const STATUSES = ['working', 'faulty', 'under_repair', 'scrapped'];

    /** Tag prefix by type, as in the old app. */
    public const PREFIXES = ['computer' => 'PC', 'monitor' => 'MON', 'keyboard' => 'KB', 'mouse' => 'MS', 'cpu' => 'CPU', 'ups' => 'UPS',
        'smartboard' => 'SB', 'projector' => 'PRJ', 'printer' => 'PRN', 'other' => 'IT'];

    protected $fillable = ['organization_id', 'lab_id', 'asset_tag', 'asset_type', 'workstation_no', 'brand', 'model', 'serial_no', 'purchase_date', 'purchase_cost',
        'warranty_expiry', 'os_license', 'license_expiry', 'status', 'power_status', 'power_updated_at', 'power_updated_by', 'remarks', 'added_by'];

    protected function casts(): array
    {
        return ['purchase_date' => 'date:Y-m-d', 'warranty_expiry' => 'date:Y-m-d', 'license_expiry' => 'date:Y-m-d', 'purchase_cost' => 'decimal:2', 'power_updated_at' => 'datetime'];
    }

    public function lab(): BelongsTo
    {
        return $this->belongsTo(ItLab::class, 'lab_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(ItRepairTicket::class, 'asset_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(ItAssetTransfer::class, 'asset_id');
    }
}
