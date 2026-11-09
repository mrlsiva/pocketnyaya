<?php

namespace Tests\Feature\Practice;

use App\Models\Document;
use App\Models\LegalHold;
use App\Models\User;
use Illuminate\Support\Str;

/** C60 §4.2: a hold on a practice, matter, dispute or person reaches the records under it. */
class LegalHoldCoverageTest extends PracticeTestCase
{
    private function hold(string $type, string $id): LegalHold
    {
        $hold = new LegalHold(['holdable_type' => $type, 'holdable_id' => $id, 'reason' => 'Synthetic hold']);
        $hold->forceFill(['placed_by' => User::factory()->create()->id, 'placed_at' => now()])->save();

        return $hold;
    }

    private function document(array $attributes): Document
    {
        return (new Document)->forceFill($attributes + ['id' => (string) Str::uuid()]);
    }

    public function test_document_is_covered_by_holds_on_what_it_belongs_to(): void
    {
        $firm = $this->firm();
        $matter = $this->matter($firm);
        $owner = User::factory()->create();
        $dispute = (string) Str::uuid();

        $tenantDoc = $this->document(['scope' => 'tenant', 'tenant_id' => $firm, 'matter_id' => $matter->id]);
        $personalDoc = $this->document(['scope' => 'personal', 'owner_user_id' => $owner->id]);
        $disputeDoc = $this->document(['scope' => 'platform', 'odr_dispute_id' => $dispute]);
        $this->assertFalse(LegalHold::coversDocument($tenantDoc));

        $matterHold = $this->hold('matter', $matter->id);
        $this->assertTrue(LegalHold::coversDocument($tenantDoc));
        $matterHold->forceFill(['released_at' => now(), 'released_by' => $matterHold->placed_by])->save();
        $this->assertFalse(LegalHold::coversDocument($tenantDoc));

        $this->hold('tenant', $firm);
        $this->assertTrue(LegalHold::coversDocument($tenantDoc));
        $this->assertFalse(LegalHold::coversDocument($personalDoc), 'A firm hold never reaches a personal vault.');

        $this->hold('user', $owner->id);
        $this->assertTrue(LegalHold::coversDocument($personalDoc));
        $this->assertFalse(LegalHold::coversDocument($disputeDoc));
        $this->hold('odr_dispute', $dispute);
        $this->assertTrue(LegalHold::coversDocument($disputeDoc));
    }

    public function test_held_matters_and_clients_cannot_be_removed(): void
    {
        $firm = $this->firm();
        $client = $this->client($firm);
        $matter = $this->matter($firm, $client);
        $partner = $this->member($firm, 'Managing Partner');
        $hold = $this->hold('matter', $matter->id);

        $this->inTenant($partner, $firm);
        $this->delete("/practice/matters/{$matter->id}")->assertStatus(422);
        $this->delete("/practice/clients/{$client->id}")->assertStatus(422);
        $this->assertFalse($matter->fresh()->trashed());
        $this->assertFalse($client->fresh()->trashed());

        $hold->forceFill(['released_at' => now(), 'released_by' => $hold->placed_by])->save();
        $tenantHold = $this->hold('tenant', $firm);
        $this->delete("/practice/matters/{$matter->id}")->assertStatus(422);

        $tenantHold->forceFill(['released_at' => now(), 'released_by' => $tenantHold->placed_by])->save();
        $other = $this->firm();
        $this->hold('tenant', $other);
        $this->delete("/practice/matters/{$matter->id}")->assertRedirect();
        $this->assertTrue($matter->fresh()->trashed(), 'Another firm\'s hold has no effect here.');
    }
}
