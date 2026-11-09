<?php

namespace Database\Factories;

use App\Models\EncryptionKeyVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Metadata only: there is no key material here, and key_ref is a placeholder.
 *
 * @extends Factory<EncryptionKeyVersion>
 */
class EncryptionKeyVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1000, 30000),
            'purpose' => 'documents',
            'provider' => 'local',
            'key_ref' => 'test-key-ref',
            'kcv' => bin2hex(random_bytes(8)),
            'status' => 'active',
            'activated_at' => now(),
        ];
    }
}
