<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows its room / parent. */
class ResidencyBillShare extends Model
{
    protected $table = 'residency_bill_shares';

    public $timestamps = false;

    protected $fillable = ['bill_id', 'occupant_id', 'amount_due', 'paid_at', 'marked_paid_by'];

    protected function casts(): array
    {
        return ['amount_due' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(ResidencyBill::class, 'bill_id');
    }

    public function occupant(): BelongsTo
    {
        return $this->belongsTo(ResidencyOccupant::class, 'occupant_id');
    }
}
