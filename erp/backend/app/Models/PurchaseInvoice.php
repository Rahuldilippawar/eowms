<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'purchase_order_id', 'department_id', 'vendor_id', 'invoice_number', 'invoice_date', 'goods_amount',
        'other_charges', 'total_amount', 'remarks', 'created_by'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date:Y-m-d', 'goods_amount' => 'decimal:2', 'other_charges' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancePayment::class, 'source_id')->where('source_type', 'purchase_invoice')->orderBy('payment_date')->orderBy('id');
    }
}
