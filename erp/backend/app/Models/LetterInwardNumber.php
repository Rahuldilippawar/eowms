<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterInwardNumber extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['letter_id', 'department_id', 'inward_no', 'fy_start_year'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }
}
