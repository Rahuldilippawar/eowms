<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry on a task's timeline (append-only; tenancy follows the task). */
class TaskUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'user_id', 'type', 'from_status', 'to_status', 'body'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
