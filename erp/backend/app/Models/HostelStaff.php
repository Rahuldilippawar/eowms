<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows the hostel. */
class HostelStaff extends Model
{
    protected $table = 'hostel_staff';

    public $timestamps = false;

    protected $fillable = ['hostel_id', 'user_id', 'role'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
