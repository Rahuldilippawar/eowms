<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancePayment extends Model
{
    use BelongsToOrganization;

    public const TYPES = ['advance', 'partial', 'final'];

    protected $fillable = [
        'organization_id', 'department_id', 'source_type', 'source_id', 'amount', 'payment_date', 'payment_type', 'payment_mode',
        'for_month', 'cycle_expiry_date', 'remarks', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_date' => 'date:Y-m-d', 'for_month' => 'date:Y-m-d', 'cycle_expiry_date' => 'date:Y-m-d'];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
