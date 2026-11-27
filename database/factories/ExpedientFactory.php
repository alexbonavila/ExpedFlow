<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Expedient;
use App\Models\ExpedientSequence;
use App\Models\ExpedientType;
use App\Models\Status;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expedient>
 */
class ExpedientFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'year' => ExpedientSequence::factory(),
            'sequence_number' => 1,
            'expedient_type_id' => ExpedientType::factory(),
            'requester_client_id' => Client::factory(),
            'status_id' => Status::factory(),
            'name' => fake()->words(3, true),
            'column_position' => fake()->numberBetween(1, 100_000),
            'code' => fn (array $attributes): string => sprintf(
                '%04d%03d%s',
                $attributes['year'],
                $attributes['sequence_number'],
                ExpedientType::query()->whereKey($attributes['expedient_type_id'])->sole()->code,
            ),
        ];
    }
}
