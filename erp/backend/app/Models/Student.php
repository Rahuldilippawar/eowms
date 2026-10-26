<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Student extends Model
{
    use BelongsToOrganization, HasFactory;

    /** Academic years are written like 2026-27. */
    public const ACADEMIC_YEAR_PATTERN = '/^\d{4}-\d{2}$/';

    protected $fillable = [
        'organization_id', 'institution_id', 'department_id', 'student_code', 'roll_no', 'admission_no', 'name', 'class_name',
        'section', 'year_of_study', 'academic_year', 'gender', 'date_of_birth', 'admission_date', 'guardian_name',
        'guardian_phone', 'mobile', 'email', 'is_active', 'custom',
    ];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'admission_date' => 'date', 'is_active' => 'boolean', 'year_of_study' => 'integer', 'custom' => 'array'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
