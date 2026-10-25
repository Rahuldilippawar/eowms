<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** An in-app notification for one user in one organization. */
class UserNotification extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'user_id', 'type', 'data', 'link', 'dedupe_key', 'read_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
