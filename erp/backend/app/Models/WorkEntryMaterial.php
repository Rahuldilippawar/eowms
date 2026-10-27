<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows its parent. */
class WorkEntryMaterial extends Model
{
    public $timestamps = false;

    protected $fillable = ['work_entry_id', 'item_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'item_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(WorkEntry::class, 'work_entry_id');
    }
}
