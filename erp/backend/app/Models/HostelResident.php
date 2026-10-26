<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Tenancy follows the hostel. */
class HostelResident extends Model
{
    protected $table = 'hostel_residents';

    protected $fillable = ['hostel_id', 'student_id', 'name', 'school_name', 'class_grade', 'district', 'gender', 'date_of_birth', 'admission_date', 'guardian_name', 'guardian_phone', 'is_active'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'admission_date' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(HostelAllocation::class, 'resident_id');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(HostelLeave::class, 'resident_id');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(HostelFee::class, 'resident_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(HostelHealthIncident::class, 'resident_id');
    }

    public function health(): HasOne
    {
        return $this->hasOne(HostelHealthProfile::class, 'resident_id');
    }

    public function alumnus(): HasOne
    {
        return $this->hasOne(HostelAlumnus::class, 'resident_id');
    }
}
