<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'short_name', 'code', 'logo_path', 'address', 'phone', 'email', 'website',
        'financial_year_start_month', 'settings', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
            'financial_year_start_month' => 'integer',
        ];
    }

    public function institutions(): HasMany
    {
        return $this->hasMany(Institution::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }
}
