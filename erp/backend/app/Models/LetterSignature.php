<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterSignature extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'letter_id', 'user_id', 'signer_name', 'signer_designation', 'action', 'method', 'verification_code', 'content_hash',
        'previous_hash', 'signed_at',
    ];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }
}
