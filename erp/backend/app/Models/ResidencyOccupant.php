<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResidencyOccupant extends Model
{
    use BelongsToOrganization;

    protected $table = 'residency_occupants';

    protected $fillable = ['organization_id', 'location_id', 'import_batch_id', 'occupant_type', 'user_id', 'family_of_id', 'name', 'relation', 'age', 'moved_in_date', 'status', 'vacated_date', 'vacated_remark', 'vacated_by', 'created_by'];

    protected function casts(): array
    {
        return ['moved_in_date' => 'date:Y-m-d', 'vacated_date' => 'date:Y-m-d', 'age' => 'integer'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLocation::class, 'location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function family(): HasMany
    {
        return $this->hasMany(ResidencyOccupant::class, 'family_of_id');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(ResidencyOccupant::class, 'family_of_id');
    }
}
