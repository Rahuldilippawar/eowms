<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['letter_id', 'path', 'original_name', 'mime', 'size', 'sha256', 'uploaded_by'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
