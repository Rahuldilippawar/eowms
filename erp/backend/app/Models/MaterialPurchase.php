<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialPurchase extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'institution_id', 'department_id', 'material_name', 'vendor_name', 'vendor_contact', 'quantity', 'unit',
        'rate', 'base_amount', 'transport_cost', 'gst_percent', 'gst_amount', 'total_amount', 'purchase_date', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2', 'rate' => 'decimal:2', 'base_amount' => 'decimal:2', 'transport_cost' => 'decimal:2',
            'gst_percent' => 'decimal:2', 'gst_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'purchase_date' => 'date:Y-m-d',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancePayment::class, 'source_id')->where('source_type', 'material_purchase')->orderBy('payment_date')->orderBy('id');
    }
}
