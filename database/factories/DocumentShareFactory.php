<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentShare>
 */
class DocumentShareFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'shared_by' => User::factory(),
            'grantee_type' => 'user',
            'grantee_user_id' => User::factory(),
            'permission' => 'view',
        ];
    }

    public function toTenant(string $tenantId): static
    {
        return $this->state(fn () => ['grantee_type' => 'tenant', 'grantee_user_id' => null, 'grantee_tenant_id' => $tenantId]);
    }
}
