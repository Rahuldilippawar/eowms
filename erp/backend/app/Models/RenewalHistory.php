<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One past cycle of a renewal item (tenancy follows the item). */
class RenewalHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'renewal_history';

    protected $fillable = ['renewal_item_id', 'old_expiry_date', 'old_issued_date', 'old_renewal_cost', 'old_vendor_name', 'old_reference_no', 'new_expiry_date', 'renewed_by'];

    protected function casts(): array
    {
        return ['old_expiry_date' => 'date:Y-m-d', 'old_issued_date' => 'date:Y-m-d', 'new_expiry_date' => 'date:Y-m-d', 'old_renewal_cost' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function renewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'renewed_by');
    }
}
