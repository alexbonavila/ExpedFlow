<?php

namespace Database\Factories;

use App\Models\Expedient;
use App\Models\ExpedientUserPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpedientUserPreference>
 */
class ExpedientUserPreferenceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'expedient_id' => Expedient::factory(),
        ];
    }
}
