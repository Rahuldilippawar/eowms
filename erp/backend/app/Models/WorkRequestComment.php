<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows the request. */
class WorkRequestComment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'work_request_comments';

    protected $fillable = ['work_request_id', 'user_id', 'comment'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
