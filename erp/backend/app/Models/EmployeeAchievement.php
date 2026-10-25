<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAchievement extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'employee_id', 'title', 'description', 'achieved_on', 'added_by'];

    protected function casts(): array
    {
        return ['achieved_on' => 'date:Y-m-d'];
    }

    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
