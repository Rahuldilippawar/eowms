<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItLab extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'department_id', 'name', 'location', 'capacity', 'assistant_user_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_user_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ItAsset::class, 'lab_id');
    }
}
