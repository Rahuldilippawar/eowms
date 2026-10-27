<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class AssetItemMaster extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'item_name', 'category', 'default_unit', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
