<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceLocation extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'institution_id', 'building_id', 'floor', 'name', 'location_code', 'capacity', 'is_residential', 'is_active', 'created_by', 'residency_import_batch_id'];

    protected function casts(): array
    {
        return ['is_residential' => 'boolean', 'is_active' => 'boolean'];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** Who lives here (residential locations). */
    public function residents(): HasMany
    {
        return $this->hasMany(ResidencyOccupant::class, 'location_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class, 'location_id');
    }
}
