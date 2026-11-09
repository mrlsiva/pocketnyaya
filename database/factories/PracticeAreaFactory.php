<?php

namespace Database\Factories;

use App\Models\PracticeArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PracticeArea>
 */
class PracticeAreaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $number = fake()->unique()->numerify('#####');

        return ['slug' => "test-area-{$number}", 'name' => "Test Area {$number}"];
    }
}
