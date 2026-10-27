<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['draft', 'issued', 'partly_received', 'received', 'cancelled'];

    protected $fillable = ['organization_id', 'department_id', 'vendor_id', 'quotation_id', 'po_number', 'order_date', 'expected_date', 'status',
        'subtotal', 'gst_amount', 'total_amount', 'terms', 'created_by', 'issued_by', 'issued_at'];

    protected function casts(): array
    {
        return ['order_date' => 'date:Y-m-d', 'expected_date' => 'date:Y-m-d', 'subtotal' => 'decimal:2', 'gst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2', 'issued_at' => 'datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(PurchaseQuotation::class, 'quotation_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class, 'parent_id')->where('parent_type', 'purchase_order')->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('received_date')->orderBy('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class)->orderBy('invoice_date')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
