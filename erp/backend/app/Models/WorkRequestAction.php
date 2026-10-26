<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows the request. */
class WorkRequestAction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'work_request_actions';

    protected $fillable = ['work_request_id', 'action_type', 'old_status', 'new_status', 'remark', 'changed_by'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
