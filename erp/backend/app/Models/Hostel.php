<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hostel extends Model
{
    use BelongsToOrganization;

    public const GENDERS = ['boys', 'girls', 'mixed'];

    public const SESSIONS = ['morning', 'afternoon', 'evening'];

    protected $fillable = ['organization_id', 'institution_id', 'department_id', 'name', 'gender', 'capacity', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(HostelStaff::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(HostelRoom::class)->orderBy('room_number');
    }

    public function residents(): HasMany
    {
        return $this->hasMany(HostelResident::class);
    }
}
