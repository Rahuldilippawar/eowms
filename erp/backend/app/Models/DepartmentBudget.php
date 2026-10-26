<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentBudget extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'department_id', 'fy_start_year', 'allocated_amount', 'allocated_by'];

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:2', 'fy_start_year' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
