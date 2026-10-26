<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkRequestView extends Model
{
    public $timestamps = false;

    protected $fillable = ['work_request_id', 'user_id', 'first_viewed_at', 'last_viewed_at'];

    protected function casts(): array
    {
        return ['first_viewed_at' => 'datetime', 'last_viewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
