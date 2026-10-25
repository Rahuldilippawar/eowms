<?php

namespace Database\Factories;

use App\Enums\InstitutionType;
use App\Models\Institution;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Institution> */
class InstitutionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'type' => fake()->randomElement(InstitutionType::cases()),
            'name' => fake()->company().' College',
            'code' => strtoupper(fake()->unique()->bothify('INS-###')),
            'is_active' => true,
        ];
    }
}
