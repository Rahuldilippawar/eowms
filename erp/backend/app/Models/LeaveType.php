<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'code', 'name', 'days_per_year', 'is_paid', 'is_active'];

    protected function casts(): array
    {
        return ['days_per_year' => 'decimal:1', 'is_paid' => 'boolean', 'is_active' => 'boolean'];
    }
}
