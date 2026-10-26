<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RenewalAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['renewal_item_id', 'cycle_expiry_date', 'title', 'path', 'original_name', 'mime', 'size', 'uploaded_by'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['cycle_expiry_date' => 'date:Y-m-d', 'created_at' => 'datetime'];
    }
}
