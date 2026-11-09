<?php

namespace Database\Factories;

use App\Models\AdvocateProfile;
use App\Models\AdvocateQualification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvocateQualification>
 */
class AdvocateQualificationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'advocate_profile_id' => AdvocateProfile::factory(),
            'degree' => 'LL.B. (test)',
            'institution' => 'Test Law College',
            'year_of_passing' => 2014,
        ];
    }
}
