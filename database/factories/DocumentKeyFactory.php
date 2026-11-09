<?php

namespace Database\Factories;

use App\Models\DocumentKey;
use App\Models\DocumentVersion;
use App\Models\EncryptionKeyVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Random placeholder bytes, not a real wrapped key.
 *
 * @extends Factory<DocumentKey>
 */
class DocumentKeyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'document_version_id' => DocumentVersion::factory(),
            'kek_version_id' => EncryptionKeyVersion::factory(),
            'wrapped_dek' => base64_encode(random_bytes(60)),
        ];
    }
}
