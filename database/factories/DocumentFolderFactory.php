<?php

namespace Database\Factories;

use App\Models\DocumentFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentFolder>
 */
class DocumentFolderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scope' => 'personal',
            'owner_user_id' => User::factory(),
            'name' => 'Test Folder',
        ];
    }

    public function inTenant(string $tenantId): static
    {
        return $this->state(fn () => ['scope' => 'tenant', 'tenant_id' => $tenantId]);
    }
}
