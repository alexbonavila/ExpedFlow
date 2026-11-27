<?php

namespace Database\Factories;

use App\Models\ExpedientType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpedientType>
 */
class ExpedientTypeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'code' => fake()->unique()->numerify('##'),
        ];
    }
}
