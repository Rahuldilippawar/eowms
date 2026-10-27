<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseQuotation extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'department_id', 'vendor_id', 'reference_no', 'quotation_date', 'valid_until', 'status', 'total_amount', 'remarks', 'created_by'];

    protected function casts(): array
    {
        return ['quotation_date' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d', 'total_amount' => 'decimal:2'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class, 'parent_id')->where('parent_type', 'purchase_quotation')->orderBy('id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class, 'quotation_id');
    }
}
