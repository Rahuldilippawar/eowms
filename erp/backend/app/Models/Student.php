<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Student extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'institution_id', 'department_id', 'student_code', 'name', 'class_name', 'section',
        'gender', 'date_of_birth', 'admission_date', 'guardian_name', 'guardian_phone', 'is_active',
    ];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'admission_date' => 'date', 'is_active' => 'boolean'];
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
