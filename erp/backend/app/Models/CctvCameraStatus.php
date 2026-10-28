<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows its checkup. */
class CctvCameraStatus extends Model
{
    public const STATUSES = ['working', 'not_working', 'needs_attention'];

    public $timestamps = false;

    protected $fillable = ['checkup_id', 'camera_id', 'status', 'remarks'];

    public function camera(): BelongsTo
    {
        return $this->belongsTo(CctvCamera::class, 'camera_id');
    }
}
