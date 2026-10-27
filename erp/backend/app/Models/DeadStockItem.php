<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeadStockItem extends Model
{
    use BelongsToOrganization;

    public const CONDITIONS = ['good', 'repairable', 'bad', 'scrap'];

    protected $fillable = ['organization_id', 'institution_id', 'department_id', 'item_master_id', 'item_code', 'item_name', 'category', 'quantity', 'unit',
        'purchase_date', 'purchase_cost', 'is_new_purchase', 'condition', 'location', 'remarks', 'added_by'];

    protected function casts(): array
    {
        return ['purchase_date' => 'date:Y-m-d', 'purchase_cost' => 'decimal:2', 'is_new_purchase' => 'boolean', 'quantity' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(AssetItemMaster::class, 'item_master_id');
    }

    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
