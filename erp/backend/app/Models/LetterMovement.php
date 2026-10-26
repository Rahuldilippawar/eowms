<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry on a file's movement register (append-only; tenancy follows the letter). */
class LetterMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['letter_id', 'type', 'from_user_id', 'to_user_id', 'step_order', 'remark'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
