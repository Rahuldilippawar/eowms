<?php

namespace App\Models;

use App\Enums\InstitutionType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = ['organization_id', 'type', 'name', 'code', 'address', 'phone', 'email', 'head_user_id', 'is_active'];

    protected function casts(): array
    {
        return ['type' => InstitutionType::class, 'is_active' => 'boolean'];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }
}
