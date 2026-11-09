<?php

namespace Database\Factories;

use App\Models\BarCouncil;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BarCouncil>
 */
class BarCouncilFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'state_id' => State::factory(),
            'code' => 'TEST-'.fake()->unique()->numerify('#####'),
            'name' => 'Test Bar Council '.fake()->unique()->numerify('#####'),
        ];
    }
}
