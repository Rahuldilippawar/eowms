<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Student */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_code' => $this->student_code,
            'name' => $this->name,
            'roll_no' => $this->roll_no,
            'admission_no' => $this->admission_no,
            'institution' => $this->whenLoaded('institution', fn () => ['id' => $this->institution->id, 'name' => $this->institution->name]),
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'class_name' => $this->class_name,
            'section' => $this->section,
            'year_of_study' => $this->year_of_study,
            'academic_year' => $this->academic_year,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'admission_date' => $this->admission_date?->toDateString(),
            'guardian_name' => $this->guardian_name,
            'guardian_phone' => $this->guardian_phone,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'custom' => $this->custom ?? (object) [],
        ];
    }
}
