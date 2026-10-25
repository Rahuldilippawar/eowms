<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'institution_id' => null,
            'name' => fake()->randomElement(['IT', 'HR', 'Accounts', 'Maintenance', 'Store']).' '.fake()->unique()->numberBetween(1, 9999),
            'code' => strtoupper(fake()->unique()->bothify('D-####')),
            'is_active' => true,
        ];
    }
}
