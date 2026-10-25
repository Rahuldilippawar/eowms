<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'short_name' => strtoupper(fake()->lexify('???')),
            'code' => strtoupper(fake()->unique()->bothify('ORG-####')),
            'email' => fake()->companyEmail(),
            'financial_year_start_month' => 4,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
