<?php

namespace Database\Factories;

use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $number = fake()->unique()->numerify('#####');

        return ['code' => "t{$number}", 'name' => "Test Language {$number}"];
    }
}
