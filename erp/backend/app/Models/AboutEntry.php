<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A section of the organization's About page, or a contributor (erp/docs/ABOUT-AND-TV.md). */
class AboutEntry extends Model
{
    use BelongsToOrganization;

    public const KINDS = ['deity', 'sadguru', 'guide', 'contributor'];

    protected $fillable = ['organization_id', 'kind', 'title', 'subtitle', 'body', 'photo_path', 'sort_order', 'is_active'];

    protected $hidden = ['photo_path'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
