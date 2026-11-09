<?php

namespace Database\Factories;

use App\Models\Court;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Court>
 */
class CourtFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'court_type' => 'district',
            'name' => 'Test Court '.fake()->unique()->numerify('#####'),
            'state_id' => State::factory(),
        ];
    }
}
