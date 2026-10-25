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
            'institution' => $this->whenLoaded('institution', fn () => ['id' => $this->institution->id, 'name' => $this->institution->name]),
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'class_name' => $this->class_name,
            'section' => $this->section,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'admission_date' => $this->admission_date?->toDateString(),
            'guardian_name' => $this->guardian_name,
            'guardian_phone' => $this->guardian_phone,
            'is_active' => $this->is_active,
        ];
    }
}
