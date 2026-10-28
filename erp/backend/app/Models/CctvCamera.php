<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CctvCamera extends Model
{
    use BelongsToOrganization;

    public const TYPES = ['dome', 'bullet', 'ptz', 'other'];

    protected $fillable = ['organization_id', 'dvr_id', 'name', 'location', 'camera_type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function dvr(): BelongsTo
    {
        return $this->belongsTo(CctvDvr::class, 'dvr_id');
    }
}
