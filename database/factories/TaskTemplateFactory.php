<?php

namespace Database\Factories;

use App\Models\ExpedientType;
use App\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskTemplate>
 */
class TaskTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'expedient_type_id' => ExpedientType::factory(),
            'name' => fake()->words(3, true),
            'position' => fake()->numberBetween(1, 100),
        ];
    }
}
