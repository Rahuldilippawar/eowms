<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CctvDvr extends Model
{
    use BelongsToOrganization;

    protected $table = 'cctv_dvrs';

    protected $fillable = ['organization_id', 'department_id', 'name', 'location', 'channel_capacity', 'storage_tb', 'installed_date', 'is_active'];

    protected function casts(): array
    {
        return ['storage_tb' => 'decimal:2', 'installed_date' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function cameras(): HasMany
    {
        return $this->hasMany(CctvCamera::class, 'dvr_id');
    }

    public function checkups(): HasMany
    {
        return $this->hasMany(CctvCheckup::class, 'dvr_id');
    }
}
