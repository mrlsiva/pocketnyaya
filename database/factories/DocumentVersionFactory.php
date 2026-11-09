<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Metadata only: no file is written, and the encrypted values are random placeholders.
 *
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version_number' => 1,
            'storage_disk' => 'documents',
            'storage_region' => 'test-region',
            'storage_path' => 'test/'.fake()->uuid().'/1.bin',
            'original_filename_enc' => base64_encode(random_bytes(32)),
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'plaintext_mac' => bin2hex(random_bytes(32)),
            'chunk_size' => 4194304,
            'nonce_prefix' => random_bytes(8),
            'uploaded_by' => fn (array $attributes) => Document::query()->withTrashed()->findOrFail($attributes['document_id'])->owner_user_id ?? User::factory(),
        ];
    }
}
