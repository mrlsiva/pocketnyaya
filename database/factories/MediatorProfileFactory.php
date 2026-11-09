<?php

namespace Database\Factories;

use App\Models\MediatorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only.
 *
 * @extends Factory<MediatorProfile>
 */
class MediatorProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'accreditation_body' => 'Test Accreditation Body',
            'accreditation_number' => 'TEST-MED-'.fake()->unique()->numerify('######'),
            'accreditation_valid_until' => now()->addYear()->toDateString(),
        ];
    }
}
