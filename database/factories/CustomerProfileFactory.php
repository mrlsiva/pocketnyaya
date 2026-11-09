<?php

namespace Database\Factories;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only.
 *
 * @extends Factory<CustomerProfile>
 */
class CustomerProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'display_name' => 'Test Customer',
            'is_business' => false,
        ];
    }
}
