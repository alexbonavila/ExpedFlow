<?php

namespace Database\Factories;

use App\Models\ExpedientSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpedientSequence>
 */
class ExpedientSequenceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'year' => fake()->unique()->numberBetween(2000, 9999),
            'last_number' => 1,
        ];
    }
}
