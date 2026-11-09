<?php

namespace Database\Factories;

use App\Models\AdvocateProfile;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VerificationRequest>
 */
class VerificationRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'advocate_profile_id' => AdvocateProfile::factory(),
            'submitted_at' => now(),
            'checklist' => ['enrollment_certificate' => false],
        ];
    }
}
