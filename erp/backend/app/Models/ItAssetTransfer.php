<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows its asset. */
class ItAssetTransfer extends Model
{
    protected $fillable = ['asset_id', 'from_lab_id', 'to_lab_id', 'remarks', 'transferred_by'];

    public function from(): BelongsTo
    {
        return $this->belongsTo(ItLab::class, 'from_lab_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(ItLab::class, 'to_lab_id');
    }

    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
