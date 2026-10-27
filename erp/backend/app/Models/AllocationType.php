<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AllocationType extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'is_loan', 'fields', 'is_active'];

    protected function casts(): array
    {
        return ['is_loan' => 'boolean', 'fields' => 'array', 'is_active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AllocationItem::class, 'type_id');
    }
}
