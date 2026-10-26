<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows the grievance. */
class GrievanceComment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['grievance_id', 'user_id', 'author_role', 'comment'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
