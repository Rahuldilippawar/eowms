<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenancy follows its room / parent. */
class ResidencyInspection extends Model
{
    protected $table = 'residency_inspections';

    protected $fillable = ['location_id', 'inspection_date', 'condition_rating', 'remarks', 'inspected_by'];

    protected function casts(): array
    {
        return ['inspection_date' => 'date:Y-m-d'];
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
