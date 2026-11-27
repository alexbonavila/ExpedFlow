<?php

namespace Database\Factories;

use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskTemplateChecklistItem>
 */
class TaskTemplateChecklistItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'task_template_id' => TaskTemplate::factory(),
            'name' => fake()->words(3, true),
            'position' => fake()->numberBetween(1, 100),
        ];
    }
}
