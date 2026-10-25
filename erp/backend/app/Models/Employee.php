<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Employee extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'user_id', 'employee_code', 'name', 'designation', 'phone', 'email',
        'institution_id', 'department_id', 'reporting_officer_id', 'joining_date', 'is_active',
    ];

    protected function casts(): array
    {
        return ['joining_date' => 'date', 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function reportingOfficer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reporting_officer_id');
    }

    /** Additional departments (besides the primary one). */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'employee_department');
    }
}
