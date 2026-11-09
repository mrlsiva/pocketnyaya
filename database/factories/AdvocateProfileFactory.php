<?php

namespace Database\Factories;

use App\Models\AdvocateProfile;
use App\Models\BarCouncil;
use App\Models\City;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only.
 *
 * @extends Factory<AdvocateProfile>
 */
class AdvocateProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bar_council_id' => BarCouncil::factory(),
            'enrollment_number' => 'TEST/'.fake()->unique()->numerify('######').'/2015',
            'enrollment_date' => '2015-06-15',
            'bio' => 'Synthetic test biography.',
            'city_id' => City::factory(),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => ['verification_status' => 'verified', 'verified_at' => now()]);
    }

    public function listed(): static
    {
        return $this->verified()->state(fn () => ['is_directory_listed' => true]);
    }
}
