<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'institution_id', 'name', 'created_by', 'residency_import_batch_id'];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(MaintenanceLocation::class);
    }
}
