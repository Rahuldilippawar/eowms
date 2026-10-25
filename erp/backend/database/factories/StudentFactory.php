<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Student> */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_code' => strtoupper(fake()->unique()->bothify('S-#####')),
            'name' => fake()->name(),
            'class_name' => (string) fake()->numberBetween(1, 12),
            'is_active' => true,
        ];
    }

    public function in(Institution $institution): static
    {
        return $this->state(['organization_id' => $institution->organization_id, 'institution_id' => $institution->id]);
    }
}
