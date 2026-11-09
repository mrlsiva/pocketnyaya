<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only.
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scope' => 'personal',
            'owner_user_id' => User::factory(),
            'title' => 'Synthetic test document',
            'category' => 'other',
        ];
    }

    public function inTenant(string $tenantId): static
    {
        return $this->state(fn () => ['scope' => 'tenant', 'tenant_id' => $tenantId]);
    }

    public function forVerification(VerificationRequest $request): static
    {
        return $this->state(fn () => [
            'scope' => 'platform',
            'category' => 'verification',
            'verification_request_id' => $request->id,
            'owner_user_id' => $request->applicantUserId(),
        ]);
    }
}
