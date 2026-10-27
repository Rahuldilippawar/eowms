<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToOrganization;

    public const TYPES = ['receive', 'issue', 'adjust'];

    protected $fillable = ['organization_id', 'item_id', 'movement_type', 'quantity', 'unit_cost', 'department_id', 'issued_to', 'source_type', 'source_id', 'movement_date', 'remarks', 'created_by'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_cost' => 'decimal:2', 'movement_date' => 'date:Y-m-d'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'item_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
