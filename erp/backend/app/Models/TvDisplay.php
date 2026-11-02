<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A notice-board screen's secret link (erp/docs/ABOUT-AND-TV.md). */
class TvDisplay extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'token_hash', 'created_by', 'last_seen_at', 'is_active'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }
}
