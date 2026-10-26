<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tenancy follows the hostel. */
class HostelHealthProfile extends Model
{
    protected $table = 'hostel_health_profiles';

    protected $fillable = ['resident_id', 'blood_group', 'allergies', 'regular_medication', 'emergency_contact_name', 'emergency_contact_phone', 'medical_notes'];
}
