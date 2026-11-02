<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A device key someone signs in or confirms with (fingerprint, face or device PIN). */
class WebauthnCredential extends Model
{
    protected $fillable = ['user_id', 'credential_id', 'public_key', 'algorithm', 'sign_count', 'name', 'transports', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['transports' => 'array', 'last_used_at' => 'datetime', 'sign_count' => 'integer', 'algorithm' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
