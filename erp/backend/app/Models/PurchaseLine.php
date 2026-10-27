<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows its parent. */
class PurchaseLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['parent_type', 'parent_id', 'item_id', 'description', 'quantity', 'unit', 'rate', 'gst_percent', 'amount', 'received_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'rate' => 'decimal:2', 'gst_percent' => 'decimal:2', 'amount' => 'decimal:2', 'received_quantity' => 'decimal:2'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'item_id');
    }
}
