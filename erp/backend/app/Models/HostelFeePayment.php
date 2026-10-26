<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows the hostel. */
class HostelFeePayment extends Model
{
    protected $table = 'hostel_fee_payments';

    protected $fillable = ['fee_id', 'amount', 'payment_mode', 'paid_on', 'remarks', 'received_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date:Y-m-d'];
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
