<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_code' => strtoupper(fake()->unique()->bothify('E-####')),
            'name' => fake()->name(),
            'designation' => fake()->jobTitle(),
            'is_active' => true,
        ];
    }

    /** Places the employee in $department (and its organization / institution). */
    public function in(Department $department): static
    {
        return $this->state([
            'organization_id' => $department->organization_id,
            'department_id' => $department->id,
            'institution_id' => $department->institution_id,
        ]);
    }
}
