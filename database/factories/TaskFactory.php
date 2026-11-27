<?php

namespace Database\Factories;

use App\Models\Expedient;
use App\Models\Status;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'expedient_id' => Expedient::factory(),
            'status_id' => Status::factory(),
            'name' => fake()->words(3, true),
            'position' => fake()->unique()->numberBetween(1, 1_000_000),
        ];
    }
}
