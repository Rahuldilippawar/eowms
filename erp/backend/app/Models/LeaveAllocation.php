<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class LeaveAllocation extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'employee_id', 'leave_type_id', 'year', 'days', 'updated_by'];

    protected function casts(): array
    {
        return ['days' => 'float', 'year' => 'integer'];
    }
}
