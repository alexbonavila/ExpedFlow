<?php

namespace Database\Factories;

use App\Models\Expedient;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => 'filesystem',
            'operation' => 'provision',
            'message' => 'Synthetic storage incident.',
        ];
    }

    public function forHistoricalExpedient(Expedient $expedient): static
    {
        return $this->for($expedient)->state(['expedient_code' => $expedient->code]);
    }
}
