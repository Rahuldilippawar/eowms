<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetTransaction extends Model
{
    use BelongsToOrganization;

    public const CATEGORIES = ['purchase', 'maintenance', 'fuel', 'renewal', 'other'];

    protected $fillable = [
        'organization_id', 'department_id', 'fy_start_year', 'amount', 'category', 'source_type', 'source_id', 'description',
        'spend_date', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'spend_date' => 'date:Y-m-d', 'fy_start_year' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
