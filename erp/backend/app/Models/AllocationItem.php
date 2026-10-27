<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AllocationItem extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'type_id', 'institution_id', 'name', 'default_location', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AllocationType::class, 'type_id');
    }

    public function current(): HasOne
    {
        return $this->hasOne(AssetAllocation::class, 'item_id')->whereNull('returned_at');
    }
}
