<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'institution_id', 'holiday_date', 'name', 'created_by'];

    protected function casts(): array
    {
        return ['holiday_date' => 'date:Y-m-d'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
