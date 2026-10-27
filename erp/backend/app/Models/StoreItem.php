<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreItem extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'department_id', 'item_name', 'category', 'unit', 'reorder_level', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['reorder_level' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'item_id');
    }
}
