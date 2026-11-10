<?php

namespace Tests\Feature\Platform;

use App\Models\Client;
use App\Models\Document;
use App\Models\LegalHold;
use App\Models\RetentionRule;
use App\Models\User;
use App\Services\Documents\DocumentVault;
use App\Services\Documents\LocalKeyProvider;
use App\Services\RetentionService;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Practice\PracticeTestCase;

/** Retention: dry run, two people, holds re-checked, and nothing without an approved supported rule. */
class RetentionTest extends PracticeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config(['pocketnyaya.document_key_dir' => storage_path('framework/testing/keys/'.uniqid())]);
        app(LocalKeyProvider::class)->createVersion();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) config('pocketnyaya.document_key_dir'));
        parent::tearDown();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $platform = (string) AccessContext::platformTenantId();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $platform, 'user_id' => $user->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($user, $role, $platform);

        return $user;
    }

    private function rule(string $category, string $action, int $months = 12, string $trigger = 'tenant_closure'): RetentionRule
    {
        // Each new rule is the next version of its category, as the drafting screen does.
        $version = (int) RetentionRule::query()->where('data_category', $category)->max('version') + 1;
        $rule = new RetentionRule(['data_category' => $category, 'version' => $version, 'retention_months' => $months, 'trigger_event' => $trigger, 'on_expiry' => $action, 'on_erasure_request' => 'retain', 'legal_basis_note' => 'Synthetic']);
        $rule->forceFill(['status' => 'approved', 'drafted_by' => User::factory()->create()->id, 'approved_by' => User::factory()->create()->id, 'approved_at' => now()])->save();

        return $rule;
    }

    /** A practice that entered retention $monthsAgo months ago, with a client, a matter and an encrypted document. */
    private function closedPractice(int $monthsAgo): array
    {
        $firm = $this->firm();
        $owner = User::factory()->create();
        $client = $this->client($firm, 'Real Client Name');
        $client->forceFill(['email' => 'client@example.test', 'pan' => 'ABCDE1234F'])->save();
        $matter = $this->matter($firm, $client);
        $document = new Document(['title' => 'Pleading', 'category' => 'other']);
        $document->forceFill(['scope' => 'tenant', 'tenant_id' => $firm, 'owner_user_id' => $owner->id, 'matter_id' => $matter->id])->save();
        $version = app(DocumentVault::class)->addVersion($document, UploadedFile::fake()->createWithContent('p.pdf', 'SYNTHETIC'), $owner);
        DB::table('tenant_offboarding')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $firm, 'status' => 'retention', 'reason' => 'Synthetic', 'requested_by' => $owner->id, 'requested_at' => now()->subMonths($monthsAgo + 1),
            'approved_by' => User::factory()->create()->id, 'approved_at' => now()->subMonths($monthsAgo), 'export_waived_reason' => 'Synthetic',
            'retention_started_at' => now()->subMonths($monthsAgo), 'disposal_status' => 'blocked_no_rule', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$firm, $client, $matter, $document, $version];
    }

    public function test_nothing_happens_without_an_approved_supported_rule(): void
    {
        $this->closedPractice(24);
        $service = app(RetentionService::class);
        $this->assertSame(0, $service->report());

        $this->rule('closed_practice_documents', 'anonymize');
        $this->rule('some_other_category', 'delete');
        $this->rule('closed_practice_clients', 'anonymize', 12, 'creation');
        $this->assertSame(0, $service->report(), 'A mismatched action, unknown category or wrong trigger does nothing.');
    }

    public function test_report_lists_only_due_practices_and_changes_nothing(): void
    {
        [, $client, , , $version] = $this->closedPractice(24);
        $this->closedPractice(3);
        $this->rule('closed_practice_documents', 'delete', 12);
        $this->rule('closed_practice_clients', 'anonymize', 12);

        $this->assertSame(2, app(RetentionService::class)->report());
        $this->assertSame(1, (int) DB::table('retention_batches')->where('data_category', 'closed_practice_clients')->value('item_count'));
        $this->assertSame('Real Client Name', $client->fresh()->name);
        $this->assertFalse($version->fresh()->isShredded());

        app(RetentionService::class)->report();
        $this->assertSame(2, DB::table('retention_batches')->where('status', 'report')->count(), 'A new report supersedes the old one.');
    }

    public function test_two_people_dispose_and_holds_are_respected(): void
    {
        [$firm, $client, $matter, $document, $version] = $this->closedPractice(24);
        $this->rule('closed_practice_documents', 'delete', 12);
        $this->rule('closed_practice_clients', 'anonymize', 12);
        $service = app(RetentionService::class);
        $service->report();
        $officer = $this->staff('Compliance Officer');
        $admin = $this->staff('Super Admin');
        $docs = (string) DB::table('retention_batches')->where('data_category', 'closed_practice_documents')->value('id');
        $clients = (string) DB::table('retention_batches')->where('data_category', 'closed_practice_clients')->value('id');

        // A hold placed after the report is respected when the batch runs.
        $hold = new LegalHold(['holdable_type' => 'matter', 'holdable_id' => $matter->id, 'reason' => 'Synthetic']);
        $hold->forceFill(['placed_by' => $admin->id, 'placed_at' => now()])->save();

        $this->actingAs($officer)->withSession(['mfa_passed_for' => $officer->id])->post("/staff/privacy/retention-batches/{$docs}", ['action' => 'propose'])->assertRedirect();
        $this->post("/staff/privacy/retention-batches/{$docs}", ['action' => 'approve'])->assertForbidden();
        $this->assertThrows(fn () => $service->approveAndExecute($docs, $officer), ValidationException::class);

        $this->actingAs($admin)->withSession(['mfa_passed_for' => $admin->id])->post("/staff/privacy/retention-batches/{$docs}", ['action' => 'approve'])->assertRedirect();
        $this->assertSame(['done' => 0, 'skipped_hold' => 1, 'skipped_not_due' => 0], json_decode((string) DB::table('retention_batches')->where('id', $docs)->value('summary'), true));
        $this->assertFalse($version->fresh()->isShredded());

        $hold->forceFill(['released_at' => now(), 'released_by' => $admin->id])->save();
        $service->propose($clients, $officer);
        $this->assertSame(1, $service->approveAndExecute($clients, $admin)['done']);
        $fresh = Client::withTrashed()->find($client->id);
        $this->assertSame(RetentionService::ERASED_NAME, $fresh->name);
        $this->assertNull($fresh->email);
        $this->assertNull($fresh->pan);
        $this->assertSame('partly_disposed', DB::table('tenant_offboarding')->where('tenant_id', $firm)->value('disposal_status'));

        $service->report();
        $next = (string) DB::table('retention_batches')->where(['data_category' => 'closed_practice_documents', 'status' => 'report'])->value('id');
        $service->propose($next, $officer);
        $service->approveAndExecute($next, $admin);
        $this->assertTrue($version->fresh()->isShredded());
        $this->assertSame('retention_expiry', DB::table('document_keys')->where('document_version_id', $version->id)->value('destroyed_reason'));
        $this->assertTrue(Document::withTrashed()->find($document->id)->trashed());
        $this->assertSame('disposed', DB::table('tenant_offboarding')->where('tenant_id', $firm)->value('disposal_status'));

        $this->assertThrows(fn () => DB::table('retention_batches')->where('id', $next)->delete(), QueryException::class);
    }

    public function test_a_changed_rule_stops_a_proposed_batch(): void
    {
        $this->closedPractice(24);
        $this->rule('closed_practice_clients', 'anonymize', 12);
        $service = app(RetentionService::class);
        $service->report();
        $batch = (string) DB::table('retention_batches')->value('id');
        $service->propose($batch, $this->staff('Compliance Officer'));

        $this->rule('closed_practice_clients', 'anonymize', 12);
        $this->assertThrows(fn () => $service->approveAndExecute($batch, $this->staff('Super Admin')), ValidationException::class);
    }
}
