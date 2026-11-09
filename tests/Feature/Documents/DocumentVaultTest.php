<?php

namespace Tests\Feature\Documents;

use App\Models\AdvocateProfile;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentKey;
use App\Models\DocumentVersion;
use App\Models\EncryptionKeyVersion;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Documents\ChunkedCipher;
use App\Services\Documents\DocumentVault;
use App\Services\Documents\KeyProvider;
use App\Services\Documents\LocalKeyProvider;
use App\Services\RoleAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Practice\PracticeTestCase;

/** The real encryption pipeline, with a throwaway key folder and fake disk. */
class DocumentVaultTest extends PracticeTestCase
{
    private const SECRET = 'SYNTHETIC-SECRET-CONTENT-12345';

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

    private function upload(string $contents = self::SECRET, string $name = 'Aadhaar_Test.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function vaultDocument(): array
    {
        $owner = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($owner);
        $document = new Document(['title' => 'Vault file', 'category' => 'other']);
        $document->forceFill(['scope' => 'personal', 'owner_user_id' => $owner->id])->save();

        return [$owner, $document, app(DocumentVault::class)->addVersion($document, $this->upload(), $owner)];
    }

    public function test_file_on_disk_is_encrypted_and_reads_back_correctly(): void
    {
        [, , $version] = $this->vaultDocument();

        $raw = Storage::disk('documents')->get($version->storage_path);
        $this->assertStringNotContainsString(self::SECRET, (string) $raw);
        $this->assertSame(self::SECRET, app(DocumentVault::class)->readContents($version));
    }

    public function test_database_holds_no_plaintext_key_filename_or_content(): void
    {
        [, , $version] = $this->vaultDocument();

        $row = (array) DB::table('document_versions')->where('id', $version->id)->first();
        $key = (array) DB::table('document_keys')->where('document_version_id', $version->id)->first();

        $this->assertStringNotContainsString('Aadhaar', (string) $row['original_filename_enc']);
        $this->assertSame('Aadhaar_Test.pdf', app(DocumentVault::class)->originalFilename($version));
        $this->assertNotSame(hash('sha256', self::SECRET), $row['plaintext_mac']);
        $this->assertSame(1, $key['kek_version_id']);
        $this->assertSame(60, strlen((string) base64_decode((string) $key['wrapped_dek'], true)));
        $this->assertSame('aes-256-gcm', $row['cipher']);
        $this->assertSame(8, strlen($row['nonce_prefix']));
    }

    public function test_storage_paths_follow_the_approved_layout(): void
    {
        [$owner, $document, $version] = $this->vaultDocument();
        $this->assertSame("users/{$owner->id}/vault/{$document->id}/1.bin", $version->storage_path);

        $firm = $this->firm();
        $tenantDoc = new Document(['title' => 'T', 'category' => 'other']);
        $tenantDoc->forceFill(['scope' => 'tenant', 'tenant_id' => $firm, 'owner_user_id' => $owner->id])->save();
        $tv = app(DocumentVault::class)->addVersion($tenantDoc, $this->upload(), $owner);
        $this->assertSame("tenants/{$firm}/docs/{$tenantDoc->id}/1.bin", $tv->storage_path);
    }

    public function test_each_version_gets_its_own_number_and_data_key(): void
    {
        [$owner, $document, $first] = $this->vaultDocument();
        $second = app(DocumentVault::class)->addVersion($document, $this->upload('other'), $owner, 'Updated');

        $this->assertSame(2, $second->version_number);
        $this->assertNotSame($first->key->wrapped_dek, $second->key->wrapped_dek);
        $this->assertSame(self::SECRET, app(DocumentVault::class)->readContents($first));
        $this->assertSame('other', app(DocumentVault::class)->readContents($second));
    }

    public function test_tampered_file_fails(): void
    {
        [, , $version] = $this->vaultDocument();
        $raw = (string) Storage::disk('documents')->get($version->storage_path);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'a' ? 'b' : 'a';
        Storage::disk('documents')->put($version->storage_path, $raw);

        $this->expectException(RuntimeException::class);
        app(DocumentVault::class)->readContents($version);
    }

    public function test_truncated_file_fails(): void
    {
        [$owner, $document] = $this->vaultDocument();
        $big = str_repeat('A', ChunkedCipher::CHUNK_SIZE + 100);
        $version = app(DocumentVault::class)->addVersion($document, $this->upload($big), $owner);

        // Cuts off the final chunk, leaving a complete and authentic first chunk.
        $raw = (string) Storage::disk('documents')->get($version->storage_path);
        Storage::disk('documents')->put($version->storage_path, substr($raw, 0, 20 + ChunkedCipher::CHUNK_SIZE));

        $this->expectException(RuntimeException::class);
        app(DocumentVault::class)->readContents($version);
    }

    public function test_a_large_file_round_trips_across_chunks(): void
    {
        [$owner, $document] = $this->vaultDocument();
        $big = random_bytes(ChunkedCipher::CHUNK_SIZE * 2 + 1234);
        $version = app(DocumentVault::class)->addVersion($document, $this->upload($big), $owner);

        $this->assertSame(strlen($big), (int) $version->size_bytes);
        $this->assertTrue(hash_equals(hash('sha256', $big), hash('sha256', app(DocumentVault::class)->readContents($version))));
    }

    public function test_an_empty_file_round_trips(): void
    {
        [$owner, $document] = $this->vaultDocument();
        $version = app(DocumentVault::class)->addVersion($document, $this->upload(''), $owner);

        $this->assertSame('', app(DocumentVault::class)->readContents($version));
    }

    public function test_one_files_ciphertext_cannot_be_swapped_into_another_version(): void
    {
        [$owner, $document, $first] = $this->vaultDocument();
        $second = app(DocumentVault::class)->addVersion($document, $this->upload('other'), $owner);
        Storage::disk('documents')->put($second->storage_path, (string) Storage::disk('documents')->get($first->storage_path));

        $this->expectException(RuntimeException::class);
        app(DocumentVault::class)->readContents($second);
    }

    public function test_wrapped_key_cannot_move_to_another_version(): void
    {
        $this->expectException(RuntimeException::class);
        $keys = app(KeyProvider::class);
        $wrapped = $keys->wrap(random_bytes(32), 1, 'pocketnyaya/document-key/a');
        $keys->unwrap($wrapped, 1, 'pocketnyaya/document-key/b');
    }

    public function test_shredding_makes_the_file_and_filename_permanently_unreadable(): void
    {
        [$owner, $document, $version] = $this->vaultDocument();
        $vault = app(DocumentVault::class);
        $vault->shred($version, 'erasure');

        $fresh = DocumentVersion::query()->findOrFail($version->id);
        $this->assertTrue($fresh->isShredded());
        $this->assertNull($fresh->key->wrapped_dek);
        $this->assertNull($vault->originalFilename($fresh));
        $this->assertSame(410, Gate::forUser($owner)->inspect('download', $document)->status());

        $this->expectException(RuntimeException::class);
        $vault->readContents($fresh);
    }

    public function test_rotation_rewraps_without_touching_files_and_skips_shredded_keys(): void
    {
        [$owner, $document, $version] = $this->vaultDocument();
        $vault = app(DocumentVault::class);
        $shredded = $vault->addVersion($document, $this->upload('gone'), $owner);
        $vault->shred($shredded, 'erasure');
        $before = Storage::disk('documents')->get($version->storage_path);

        $newKey = app(LocalKeyProvider::class)->createVersion();
        $this->assertSame(1, $vault->rewrap());

        $key = DocumentKey::query()->findOrFail($version->id);
        $this->assertSame($newKey, (int) $key->kek_version_id);
        $this->assertNotNull($key->rewrapped_at);
        $this->assertSame($before, Storage::disk('documents')->get($version->storage_path));
        $this->assertSame(self::SECRET, $vault->readContents($version->fresh()));
        $this->assertSame('decrypt_only', EncryptionKeyVersion::query()->findOrFail(1)->status);
        $this->assertSame(1, (int) DocumentKey::query()->findOrFail($shredded->id)->kek_version_id);
    }

    public function test_a_wrong_key_file_is_detected_by_the_check_value(): void
    {
        [, , $version] = $this->vaultDocument();
        file_put_contents(config('pocketnyaya.document_key_dir').'/document-kek-1.key', base64_encode(random_bytes(32)));

        $this->expectExceptionMessage('failed its check value');
        app(DocumentVault::class)->readContents($version);
    }

    public function test_a_failed_upload_leaves_no_file_or_rows_behind(): void
    {
        [$owner, $document] = $this->vaultDocument();
        DB::table('encryption_key_versions')->where('id', 1)->update(['status' => 'decrypt_only']);
        $filesBefore = count(Storage::disk('documents')->allFiles());

        try {
            app(DocumentVault::class)->addVersion($document, $this->upload(), $owner);
            $this->fail('Expected the upload to fail without an active key.');
        } catch (RuntimeException) {
            $this->assertSame($filesBefore, count(Storage::disk('documents')->allFiles()));
            $this->assertSame(1, DocumentVersion::query()->where('document_id', $document->id)->count());
        }
    }

    // Through the real screens

    public function test_vault_upload_download_and_logging(): void
    {
        $owner = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($owner);
        $this->actingAs($owner);

        $this->post('/citizen/vault', ['file' => $this->upload(), 'title' => 'My deed', 'category' => 'property', 'scope' => 'tenant', 'owner_user_id' => 'x'])->assertRedirect();
        $document = Document::query()->where('title', 'My deed')->firstOrFail();
        $this->assertSame('personal', $document->scope);
        $this->assertSame($owner->id, $document->owner_user_id);

        $this->get('/citizen/vault')->assertOk()->assertSee('My deed');
        $this->get("/citizen/vault/{$document->id}")->assertOk();

        $response = $this->get("/citizen/vault/{$document->id}/download")->assertOk();
        $this->assertSame(self::SECRET, $response->streamedContent());
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $this->assertEqualsCanonicalizing(['upload', 'view', 'download'], DocumentAccessLog::query()->where('document_id', $document->id)->pluck('action')->all());

        $this->delete("/citizen/vault/{$document->id}")->assertRedirect();
        $this->get("/citizen/vault/{$document->id}")->assertNotFound();
    }

    public function test_another_user_cannot_reach_a_vault_document_through_the_screens(): void
    {
        [, $document] = $this->vaultDocument();
        $other = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($other);
        $this->actingAs($other);

        $this->get("/citizen/vault/{$document->id}")->assertNotFound();
        $this->get("/citizen/vault/{$document->id}/download")->assertNotFound();
        $this->delete("/citizen/vault/{$document->id}")->assertNotFound();
        $this->post("/citizen/vault/{$document->id}/versions", ['file' => $this->upload()])->assertNotFound();
        $this->get('/citizen/vault')->assertDontSee('Vault file');
    }

    public function test_upload_validation(): void
    {
        $owner = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($owner);
        $this->actingAs($owner);

        $this->post('/citizen/vault', ['file' => UploadedFile::fake()->create('virus.exe', 10), 'title' => 'X', 'category' => 'other'])->assertSessionHasErrors('file');
        $this->post('/citizen/vault', ['file' => UploadedFile::fake()->create('huge.pdf', 26 * 1024), 'title' => 'X', 'category' => 'other'])->assertSessionHasErrors('file');
        $this->post('/citizen/vault', ['title' => '', 'category' => 'nonsense'])->assertSessionHasErrors(['file', 'title', 'category']);
    }

    public function test_practice_document_rules_through_the_screens(): void
    {
        $firm = $this->firm();
        $other = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $junior = $this->member($firm, 'Junior Associate');
        $clerk = $this->member($firm, 'Clerk / Office Staff');
        $matter = $this->matter($firm);
        $this->assign($matter, $junior);

        // An assigned junior can add to their matter, but not a general document.
        $this->inTenant($junior, $firm);
        $this->post("/practice/matters/{$matter->id}/documents", ['file' => $this->upload(), 'title' => 'Plaint', 'category' => 'pleading'])->assertRedirect();
        $document = Document::query()->where('title', 'Plaint')->firstOrFail();
        $this->assertSame($matter->id, $document->matter_id);
        $this->assertSame($firm, $document->tenant_id);
        $this->post('/practice/documents', ['file' => $this->upload(), 'title' => 'General', 'category' => 'other'])->assertForbidden();

        // An unassigned clerk can't see or add to that matter.
        $this->inTenant($clerk, $firm);
        $this->get("/practice/documents/{$document->id}")->assertNotFound();
        $this->post("/practice/matters/{$matter->id}/documents", ['file' => $this->upload(), 'title' => 'X', 'category' => 'other'])->assertNotFound();
        $this->get('/practice/documents')->assertOk()->assertDontSee('Plaint');

        // Another firm gets nothing.
        $this->inTenant($this->member($other, 'Managing Partner'), $other);
        $this->get("/practice/documents/{$document->id}")->assertNotFound();
        $this->get("/practice/documents/{$document->id}/download")->assertNotFound();

        // Firm Admin has no document access at all.
        $this->inTenant($this->member($firm, 'Firm Admin'), $firm);
        $this->get('/practice/documents')->assertForbidden();

        // The partner sees it, can download and remove it.
        $this->inTenant($partner, $firm);
        $this->assertSame(self::SECRET, $this->get("/practice/documents/{$document->id}/download")->assertOk()->streamedContent());
        $this->delete("/practice/documents/{$document->id}")->assertRedirect();
    }

    public function test_closed_matter_rejects_new_documents_and_only_privileged_holders_can_mark_privileged(): void
    {
        $firm = $this->firm();
        $matter = $this->matter($firm, null, 'closed');
        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->post("/practice/matters/{$matter->id}/documents", ['file' => $this->upload(), 'title' => 'X', 'category' => 'other'])->assertStatus(422);

        $open = $this->matter($firm);
        $clerk = $this->member($firm, 'Clerk / Office Staff');
        $this->assign($open, $clerk, 'clerk');
        $this->inTenant($clerk, $firm);
        $this->post("/practice/matters/{$open->id}/documents", ['file' => $this->upload(), 'title' => 'X', 'category' => 'other', 'confidentiality' => 'privileged'])->assertForbidden();
    }

    public function test_a_document_cannot_use_another_firms_client_or_folder_through_the_form(): void
    {
        $firm = $this->firm();
        $foreignClient = $this->client($this->firm());
        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);

        $this->post("/practice/clients/{$foreignClient->id}/documents", ['file' => $this->upload(), 'title' => 'X', 'category' => 'other'])->assertNotFound();
        $this->assertInstanceOf(Client::class, $foreignClient->fresh());
    }

    public function test_verification_document_goes_through_the_vault_and_stays_protected(): void
    {
        $applicant = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($applicant);
        $profile = AdvocateProfile::factory()->create(['user_id' => $applicant->id]);
        $request = VerificationRequest::factory()->create(['advocate_profile_id' => $profile->id]);
        $document = Document::factory()->forVerification($request)->create();
        $vault = app(DocumentVault::class);

        $version = $vault->addVersion($document, $this->upload(), $applicant);
        $this->assertStringStartsWith("platform/docs/{$document->id}/", $version->storage_path);

        DB::table('verification_requests')->where('id', $request->id)->update(['status' => 'in_review']);
        $files = count(Storage::disk('documents')->allFiles());
        try {
            $vault->addVersion($document, $this->upload('replaced'), $applicant);
            $this->fail('Expected the replacement to be rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PN_VERIFICATION_DOC_LOCKED', $e->getMessage());
        }
        $this->assertSame($files, count(Storage::disk('documents')->allFiles()));
    }
}
