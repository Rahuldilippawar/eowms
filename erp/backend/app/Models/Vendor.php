<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'contact_person', 'phone', 'email', 'gstin', 'address', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
