<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class EmployeeFamilyMember extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'employee_id', 'name', 'relation', 'date_of_birth', 'phone', 'lives_on_campus'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'lives_on_campus' => 'boolean'];
    }
}
