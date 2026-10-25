<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class BiometricPunch extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'device_id', 'pin', 'punched_at', 'employee_id', 'result'];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime'];
    }
}
