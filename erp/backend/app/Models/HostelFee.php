<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tenancy follows the hostel. */
class HostelFee extends Model
{
    protected $table = 'hostel_fees';

    protected $fillable = ['resident_id', 'fee_period', 'amount_due', 'due_date', 'created_by'];

    protected function casts(): array
    {
        return ['amount_due' => 'decimal:2', 'due_date' => 'date:Y-m-d'];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(HostelResident::class, 'resident_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(HostelFeePayment::class, 'fee_id');
    }
}
