<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Employee extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'user_id', 'employee_code', 'name', 'designation', 'phone', 'email',
        'institution_id', 'department_id', 'reporting_officer_id', 'joining_date', 'is_active',
        'secondary_designation', 'employment_type', 'staff_group', 'teaching_mode', 'father_name', 'mother_name',
        'gender', 'date_of_birth', 'marital_status', 'blood_group', 'whatsapp', 'local_address', 'permanent_address',
        'religion', 'caste', 'caste_validity_no', 'is_disabled', 'emergency_contact_name', 'emergency_contact_relation',
        'emergency_contact_phone', 'left_date', 'custom',
    ];

    /** HR profile fields edited on the profile screen (not the basic employee form). */
    public const PROFILE_FIELDS = [
        'secondary_designation', 'employment_type', 'staff_group', 'teaching_mode', 'father_name', 'mother_name',
        'gender', 'date_of_birth', 'marital_status', 'blood_group', 'whatsapp', 'local_address', 'permanent_address',
        'religion', 'caste', 'caste_validity_no', 'is_disabled', 'emergency_contact_name', 'emergency_contact_relation',
        'emergency_contact_phone', 'left_date',
    ];

    /** What employees may change on their own profile. */
    public const SELF_FIELDS = ['whatsapp', 'local_address', 'permanent_address', 'blood_group', 'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone'];

    protected $hidden = ['photo_path'];

    protected static function booted(): void
    {
        static::creating(function (Employee $employee) {
            $employee->public_token ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'joining_date' => 'date', 'left_date' => 'date', 'date_of_birth' => 'date', 'is_active' => 'boolean',
            'is_disabled' => 'boolean', 'custom' => 'array',
        ];
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

    public function private(): HasOne
    {
        return $this->hasOne(EmployeePrivate::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class)->orderByDesc('id');
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(EmployeeFamilyMember::class)->orderBy('id');
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(EmployeeAchievement::class)->orderByDesc('achieved_on')->orderByDesc('id');
    }

    /** Additional departments (besides the primary one). */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'employee_department');
    }
}
