<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentFolder;
use App\Models\DocumentKey;
use App\Models\DocumentShare;
use App\Models\DocumentVersion;
use App\Models\EncryptionKeyVersion;
use App\Models\MediatorProfile;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DocumentSchemaTest extends DocumentTestCase
{
    // C27 folder isolation, case by case

    public function test_personal_folder_may_have_its_owners_folder_as_parent(): void
    {
        $user = User::factory()->create();
        $parent = DocumentFolder::factory()->create(['owner_user_id' => $user->id]);
        $child = DocumentFolder::factory()->create(['owner_user_id' => $user->id, 'parent_id' => $parent->id]);

        $this->assertTrue($child->parent->is($parent));
        $this->assertTrue($parent->children->first()->is($child));
    }

    public function test_personal_folder_cannot_use_another_users_folder(): void
    {
        $other = DocumentFolder::factory()->create();

        $this->assertRejected(fn () => DocumentFolder::factory()->create(['parent_id' => $other->id]), 'df_parent_owner_fk');
    }

    public function test_personal_folder_cannot_use_a_tenant_folder(): void
    {
        $firm = DocumentFolder::factory()->inTenant($this->tenant())->create();

        $this->assertRejected(fn () => DocumentFolder::factory()->create(['parent_id' => $firm->id]), 'foreign key constraint fails');
    }

    public function test_tenant_folder_cannot_use_another_tenants_folder(): void
    {
        $firmA = DocumentFolder::factory()->inTenant($this->tenant())->create();

        $this->assertRejected(fn () => DocumentFolder::factory()->inTenant($this->tenant())->create(['parent_id' => $firmA->id]), 'df_parent_tenant_fk');
    }

    public function test_tenant_folder_cannot_use_a_personal_folder(): void
    {
        $personal = DocumentFolder::factory()->create();

        $this->assertRejected(fn () => DocumentFolder::factory()->inTenant($this->tenant())->create(['parent_id' => $personal->id]), 'foreign key constraint fails');
    }

    public function test_members_of_one_tenant_may_share_folders(): void
    {
        $tenant = $this->tenant();
        $parent = DocumentFolder::factory()->inTenant($tenant)->create();
        $child = DocumentFolder::factory()->inTenant($tenant)->create(['parent_id' => $parent->id]);

        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_folder_scope_rules(): void
    {
        $this->assertRejected(fn () => DocumentFolder::factory()->create(['scope' => 'platform']), 'df_scope_values');
        $this->assertRejected(fn () => DocumentFolder::factory()->create(['scope' => 'tenant']), 'df_scope_tenant');
        $this->assertRejected(fn () => DocumentFolder::factory()->create(['tenant_id' => $this->tenant()]), 'df_scope_tenant');
    }

    public function test_folder_scope_tenant_and_owner_cannot_change(): void
    {
        $folder = DocumentFolder::factory()->create();
        $update = fn (array $change) => DB::table('document_folders')->where('id', $folder->id)->update($change);

        $this->assertRejected(fn () => $update(['owner_user_id' => User::factory()->create()->id]), 'PN_DOC_IMMUTABLE');
        $this->assertRejected(fn () => $update(['scope' => 'tenant', 'tenant_id' => $this->tenant()]), 'PN_DOC_IMMUTABLE');
        $this->assertSame(1, $update(['name' => 'Renamed']));
    }

    public function test_folder_cycles_are_rejected(): void
    {
        $user = User::factory()->create();
        $a = DocumentFolder::factory()->create(['owner_user_id' => $user->id]);
        $b = DocumentFolder::factory()->create(['owner_user_id' => $user->id, 'parent_id' => $a->id]);
        $c = DocumentFolder::factory()->create(['owner_user_id' => $user->id, 'parent_id' => $b->id]);

        $this->assertRejected(fn () => DB::table('document_folders')->where('id', $a->id)->update(['parent_id' => $c->id]), 'PN_FOLDER_CYCLE');
        $this->assertRejected(fn () => DB::table('document_folders')->where('id', $a->id)->update(['parent_id' => $a->id]), 'PN_FOLDER_CYCLE');
    }

    public function test_generated_owner_column_cannot_be_written(): void
    {
        $this->assertRejected(fn () => DB::table('documents')->insert([
            'id' => (string) Str::uuid(), 'scope' => 'personal', 'owner_user_id' => User::factory()->create()->id,
            'title' => 'T', 'category' => 'other', 'personal_owner_id' => (string) Str::uuid(),
        ]), 'personal_owner_id');
    }

    // Documents: folders, arc and scope

    public function test_personal_document_cannot_use_another_users_or_a_tenant_folder(): void
    {
        $other = DocumentFolder::factory()->create();
        $firm = DocumentFolder::factory()->inTenant($this->tenant())->create();

        $this->assertRejected(fn () => Document::factory()->create(['folder_id' => $other->id]), 'doc_folder_owner_fk');
        $this->assertRejected(fn () => Document::factory()->create(['folder_id' => $firm->id]), 'foreign key constraint fails');
    }

    public function test_tenant_document_cannot_use_another_tenants_folder(): void
    {
        $firm = DocumentFolder::factory()->inTenant($this->tenant())->create();

        $this->assertRejected(fn () => Document::factory()->inTenant($this->tenant())->create(['folder_id' => $firm->id]), 'doc_folder_tenant_fk');
    }

    public function test_documents_may_use_folders_in_their_own_scope(): void
    {
        $tenant = $this->tenant();
        $firmFolder = DocumentFolder::factory()->inTenant($tenant)->create();
        $firmDocument = Document::factory()->inTenant($tenant)->create(['folder_id' => $firmFolder->id]);
        $this->assertTrue($firmDocument->folder->is($firmFolder));

        $user = User::factory()->create();
        $folder = DocumentFolder::factory()->create(['owner_user_id' => $user->id]);
        $document = Document::factory()->create(['owner_user_id' => $user->id, 'folder_id' => $folder->id]);
        $this->assertTrue($folder->documents->first()->is($document));
    }

    public function test_scope_and_tenant_must_agree(): void
    {
        $this->assertRejected(fn () => Document::factory()->create(['scope' => 'tenant']), 'doc_scope_tenant');
        $this->assertRejected(fn () => Document::factory()->create(['tenant_id' => $this->tenant()]), 'doc_scope_tenant');
        $this->assertRejected(fn () => Document::factory()->create(['scope' => 'public']), 'doc_scope_values');
    }

    public function test_exclusive_arc_rules(): void
    {
        $tenant = $this->tenant();
        $id = fn () => (string) Str::uuid();

        $this->assertRejected(fn () => Document::factory()->inTenant($tenant)->create(['matter_id' => $id(), 'client_id' => $id()]), 'doc_arc_single');
        $this->assertRejected(fn () => Document::factory()->create(['matter_id' => $id()]), 'doc_arc_tenant');
        $this->assertRejected(fn () => Document::factory()->create(['client_id' => $id()]), 'doc_arc_tenant');
        $this->assertRejected(fn () => Document::factory()->inTenant($tenant)->create(['odr_dispute_id' => $id()]), 'doc_arc_platform');
    }

    public function test_platform_documents_cannot_use_folders(): void
    {
        $folder = DocumentFolder::factory()->create();

        $this->assertRejected(fn () => Document::factory()->create([
            'scope' => 'platform', 'owner_user_id' => $folder->owner_user_id, 'folder_id' => $folder->id,
        ]), 'doc_platform_no_folder');
    }

    public function test_document_scope_tenant_and_owner_cannot_change(): void
    {
        $document = Document::factory()->inTenant($this->tenant())->create();
        $update = fn (array $change) => DB::table('documents')->where('id', $document->id)->update($change);

        $this->assertRejected(fn () => $update(['tenant_id' => $this->tenant()]), 'PN_DOC_IMMUTABLE');
        $this->assertRejected(fn () => $update(['scope' => 'personal', 'tenant_id' => null]), 'PN_DOC_IMMUTABLE');
        $this->assertRejected(fn () => $update(['owner_user_id' => User::factory()->create()->id]), 'PN_DOC_IMMUTABLE');
    }

    public function test_invalid_value_checks(): void
    {
        $this->assertRejected(fn () => Document::factory()->create(['category' => 'secret']), 'doc_category_values');
        $this->assertRejected(fn () => Document::factory()->create(['confidentiality' => 'public']), 'doc_confidentiality_values');
        $this->assertRejected(fn () => Document::factory()->create(['source' => 'email']), 'doc_source_values');
        $this->assertRejected(fn () => Document::factory()->create(['status' => 'gone']), 'doc_status_values');
    }

    public function test_mass_assignment_cannot_set_scope_tenant_owner_or_links(): void
    {
        $document = new Document([
            'scope' => 'tenant', 'tenant_id' => 'x', 'owner_user_id' => 'x', 'matter_id' => 'x', 'client_id' => 'x',
            'odr_dispute_id' => 'x', 'verification_request_id' => 'x', 'status' => 'purged', 'personal_owner_id' => 'x',
        ]);
        $this->assertSame([], $document->getAttributes());

        $folder = new DocumentFolder(['scope' => 'tenant', 'tenant_id' => 'x', 'owner_user_id' => 'x']);
        $this->assertSame([], $folder->getAttributes());

    }

    public function test_service_records_refuse_mass_assignment_outright(): void
    {
        $attempts = [
            fn () => new DocumentVersion(['storage_path' => 'x', 'uploaded_by' => 'x']),
            fn () => new DocumentKey(['wrapped_dek' => 'x', 'kek_version_id' => 1]),
            fn () => new DocumentShare(['grantee_user_id' => 'x', 'permission' => 'download']),
            fn () => new EncryptionKeyVersion(['status' => 'active', 'key_ref' => 'x']),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Expected mass assignment to be refused.');
            } catch (MassAssignmentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    // Verification documents

    public function test_verification_relationship_works_both_ways(): void
    {
        $document = $this->verificationDocument();
        $request = $document->verificationRequest;

        $this->assertInstanceOf(VerificationRequest::class, $request);
        $this->assertTrue($request->documents->first()->is($document));
        $this->assertSame($request->advocateProfile->user_id, $document->owner_user_id);
    }

    public function test_a_verification_request_has_exactly_one_applicant(): void
    {
        $this->assertRejected(fn () => VerificationRequest::factory()->create(['advocate_profile_id' => null]), 'vr_one_applicant');
        $this->assertRejected(fn () => VerificationRequest::factory()->create(['mediator_profile_id' => MediatorProfile::factory()]), 'vr_one_applicant');
    }

    public function test_only_the_mediator_applicant_can_attach_a_document(): void
    {
        $profile = MediatorProfile::factory()->create();
        $request = VerificationRequest::factory()->create(['advocate_profile_id' => null, 'mediator_profile_id' => $profile->id]);

        $this->assertRejected(fn () => Document::factory()->create(['scope' => 'platform', 'verification_request_id' => $request->id]), 'PN_VERIFICATION_DOC_OWNER');
        $this->assertNotNull(Document::factory()->forVerification($request)->create()->id);
    }

    public function test_verification_link_requires_platform_scope(): void
    {
        $request = VerificationRequest::factory()->create();

        $this->assertRejected(fn () => Document::factory()->create([
            'verification_request_id' => $request->id, 'owner_user_id' => $request->advocateProfile->user_id,
        ]), 'doc_arc_platform');
    }

    public function test_only_the_applicant_can_attach_a_verification_document(): void
    {
        $request = VerificationRequest::factory()->create();

        $this->assertRejected(fn () => Document::factory()->create([
            'scope' => 'platform', 'verification_request_id' => $request->id,
        ]), 'PN_VERIFICATION_DOC_OWNER');
    }

    public function test_decided_or_in_review_requests_reject_new_documents(): void
    {
        foreach (['in_review', 'approved', 'rejected'] as $status) {
            $request = VerificationRequest::factory()->create(['status' => $status]);

            $this->assertRejected(fn () => Document::factory()->forVerification($request)->create(), 'PN_VERIFICATION_DOC_LOCKED');
        }
    }

    public function test_verification_document_cannot_be_replaced_after_review_starts(): void
    {
        $document = $this->verificationDocument();
        DocumentVersion::factory()->create(['document_id' => $document->id]);
        DB::table('verification_requests')->where('id', $document->verification_request_id)->update(['status' => 'in_review']);

        $this->assertRejected(fn () => DocumentVersion::factory()->create(['document_id' => $document->id, 'version_number' => 2]), 'PN_VERIFICATION_DOC_LOCKED');
        $this->assertRejected(fn () => DB::table('documents')->where('id', $document->id)->update(['title' => 'Replaced']), 'PN_VERIFICATION_DOC_LOCKED');
    }

    public function test_applicant_may_add_a_version_while_the_request_is_open(): void
    {
        foreach (['submitted', 'info_requested'] as $status) {
            $document = $this->verificationDocument($status);

            $this->assertNotNull(DocumentVersion::factory()->create(['document_id' => $document->id])->id);
        }
    }

    public function test_another_user_cannot_replace_a_verification_document(): void
    {
        $document = $this->verificationDocument();

        $this->assertRejected(fn () => DocumentVersion::factory()->create([
            'document_id' => $document->id, 'uploaded_by' => User::factory()->create()->id,
        ]), 'PN_VERIFICATION_DOC_OWNER');
    }

    public function test_verification_document_cannot_be_deleted_or_moved_to_another_request(): void
    {
        $document = $this->verificationDocument();
        $other = VerificationRequest::factory()->create();

        $this->assertRejected(fn () => $document->delete(), 'PN_PLATFORM_DOC_DELETE');
        $this->assertRejected(fn () => $document->forceDelete(), 'PN_PLATFORM_DOC_DELETE');
        $this->assertRejected(fn () => DB::table('documents')->where('id', $document->id)->delete(), 'PN_PLATFORM_DOC_DELETE');
        $this->assertRejected(fn () => DB::table('documents')->where('id', $document->id)->update(['verification_request_id' => $other->id]), 'PN_PLATFORM_DOC_LINK');
        $this->assertRejected(fn () => DB::table('documents')->where('id', $document->id)->update(['verification_request_id' => null]), 'PN_PLATFORM_DOC_LINK');
    }

    public function test_verification_request_with_documents_cannot_be_removed(): void
    {
        $document = $this->verificationDocument();

        $this->assertRejected(fn () => $document->verificationRequest->delete(), 'doc_verification_fk');
    }

    public function test_platform_documents_cannot_be_shared(): void
    {
        $document = $this->verificationDocument();

        $this->assertRejected(fn () => DocumentShare::factory()->create(['document_id' => $document->id]), 'PN_SHARE_PLATFORM_DOC');
    }

    // Append-only versions and encryption metadata

    public function test_document_versions_are_append_only(): void
    {
        $version = DocumentVersion::factory()->create();

        $this->assertRejected(fn () => DB::table('document_versions')->where('id', $version->id)->update(['mime_type' => 'text/plain']), 'PN_APPEND_ONLY');
        $this->assertRejected(fn () => $version->delete(), 'PN_APPEND_ONLY');
    }

    public function test_version_numbers_are_unique_per_document(): void
    {
        $version = DocumentVersion::factory()->create();

        $this->assertRejected(fn () => DocumentVersion::factory()->create(['document_id' => $version->document_id]), 'Duplicate entry');
    }

    public function test_version_metadata_rules(): void
    {
        $this->assertRejected(fn () => DocumentVersion::factory()->create(['cipher' => 'aes-128-cbc']), 'dv_cipher_chk');
        $this->assertRejected(fn () => DocumentVersion::factory()->create(['scan_status' => 'unknown']), 'dv_scan_chk');
        $this->assertRejected(fn () => DocumentVersion::factory()->create(['storage_disk' => 'public']), 'dv_private_disk_chk');
    }

    public function test_replaced_plaintext_columns_do_not_exist(): void
    {
        $columns = Schema::getColumnListing('document_versions');

        foreach (['original_filename', 'sha256_plaintext', 'encrypted_dek', 'key_version'] as $removed) {
            $this->assertNotContains($removed, $columns);
        }
        foreach (['original_filename_enc', 'plaintext_mac', 'chunk_size', 'nonce_prefix', 'storage_region'] as $required) {
            $this->assertContains($required, $columns);
        }
        foreach (['master_key', 'kek', 'key_material', 'secret', 'plaintext_key'] as $forbidden) {
            $this->assertNotContains($forbidden, Schema::getColumnListing('encryption_key_versions'));
        }
    }

    public function test_only_one_active_key_per_purpose(): void
    {
        EncryptionKeyVersion::factory()->create();
        $this->assertRejected(fn () => EncryptionKeyVersion::factory()->create(), 'Duplicate entry');

        $this->assertNotNull(EncryptionKeyVersion::factory()->create(['status' => 'decrypt_only'])->id);
        $this->assertNotNull(EncryptionKeyVersion::factory()->create(['purpose' => 'journal'])->id);
    }

    public function test_key_version_status_moves_forward_and_identity_is_fixed(): void
    {
        $key = EncryptionKeyVersion::factory()->create();
        $update = fn (array $change) => DB::table('encryption_key_versions')->where('id', $key->id)->update($change);

        $this->assertRejected(fn () => $update(['key_ref' => 'changed']), 'PN_KEY_VERSION_IMMUTABLE');
        $this->assertRejected(fn () => $update(['kcv' => str_repeat('0', 16)]), 'PN_KEY_VERSION_IMMUTABLE');
        $this->assertSame(1, $update(['status' => 'decrypt_only']));
        $this->assertRejected(fn () => $update(['status' => 'active']), 'PN_KEY_VERSION_STATUS');
    }

    public function test_key_version_value_checks(): void
    {
        $this->assertRejected(fn () => EncryptionKeyVersion::factory()->create(['purpose' => 'other']), 'ekv_purpose_chk');
        $this->assertRejected(fn () => EncryptionKeyVersion::factory()->create(['provider' => 'file']), 'ekv_provider_chk');
        $this->assertRejected(fn () => EncryptionKeyVersion::factory()->create(['status' => 'unknown']), 'ekv_status_chk');
    }

    public function test_data_keys_must_use_the_active_documents_key(): void
    {
        $this->assertRejected(fn () => DocumentKey::factory()->create([
            'kek_version_id' => EncryptionKeyVersion::factory()->create(['purpose' => 'journal']),
        ]), 'PN_KEY_WRAP');
        $this->assertRejected(fn () => DocumentKey::factory()->create([
            'kek_version_id' => EncryptionKeyVersion::factory()->create(['status' => 'decrypt_only']),
        ]), 'PN_KEY_WRAP');
    }

    public function test_each_version_has_one_key_and_relations_load(): void
    {
        $key = DocumentKey::factory()->create();
        $version = $key->version;

        $this->assertTrue($version->key->is($key));
        $this->assertSame('documents', $key->keyVersion->purpose);
        $this->assertTrue($key->keyVersion->documentKeys->first()->is($key));
        $this->assertRejected(fn () => DocumentKey::factory()->create([
            'document_version_id' => $version->id, 'kek_version_id' => $key->kek_version_id,
        ]), 'Duplicate entry');
    }

    public function test_rewrapping_works_until_the_key_is_destroyed(): void
    {
        $key = DocumentKey::factory()->create();
        $old = $key->kek_version_id;
        DB::table('encryption_key_versions')->where('id', $old)->update(['status' => 'decrypt_only']);
        $new = EncryptionKeyVersion::factory()->create();
        $query = DB::table('document_keys')->where('document_version_id', $key->document_version_id);

        $this->assertSame(1, $query->update(['kek_version_id' => $new->id, 'wrapped_dek' => 'cmV3cmFwcGVk', 'rewrapped_at' => now()]));
        $this->assertRejected(fn () => $query->update(['kek_version_id' => $old]), 'PN_KEY_WRAP');
    }

    public function test_destroyed_key_is_a_permanent_tombstone(): void
    {
        $key = DocumentKey::factory()->create();
        $query = DB::table('document_keys')->where('document_version_id', $key->document_version_id);

        $this->assertRejected(fn () => $query->update(['wrapped_dek' => null]), 'dk_tombstone');
        $this->assertRejected(fn () => $query->update(['wrapped_dek' => null, 'destroyed_at' => now()]), 'dk_reason');
        $this->assertRejected(fn () => $query->update(['wrapped_dek' => null, 'destroyed_at' => now(), 'destroyed_reason' => 'because']), 'dk_reason_values');

        $this->assertSame(1, $query->update(['wrapped_dek' => null, 'destroyed_at' => now(), 'destroyed_reason' => 'erasure']));

        $this->assertRejected(fn () => $query->update(['wrapped_dek' => 'cmVzdG9yZWQ=', 'destroyed_at' => null, 'destroyed_reason' => null]), 'PN_KEY_DESTROYED');
        $this->assertRejected(fn () => $query->delete(), 'PN_KEY_DELETE');
        $this->assertTrue($key->fresh()->version->isShredded());
    }

    public function test_keys_cannot_be_deleted_or_moved_to_another_version(): void
    {
        $key = DocumentKey::factory()->create();
        $query = DB::table('document_keys')->where('document_version_id', $key->document_version_id);

        $this->assertRejected(fn () => $key->delete(), 'PN_KEY_DELETE');
        $this->assertRejected(fn () => $query->update(['document_version_id' => DocumentVersion::factory()->create()->id]), 'PN_KEY_VERSION_LINK');
    }

    public function test_user_consent_key_points_at_a_real_key_version(): void
    {
        $this->assertRejected(fn () => User::factory()->create()->forceFill(['consent_key_kek_version' => 32000])->save(), 'users_consent_kek_fk');

        $key = EncryptionKeyVersion::factory()->create(['purpose' => 'journal']);
        $user = User::factory()->create();
        $user->forceFill(['consent_key_kek_version' => $key->id])->save();
        $this->assertSame($key->id, (int) $user->fresh()->consent_key_kek_version);
    }

    public function test_secrets_never_appear_in_serialized_models(): void
    {
        $key = DocumentKey::factory()->create();
        $version = $key->version;

        $this->assertArrayNotHasKey('wrapped_dek', $key->toArray());
        foreach (['storage_disk', 'storage_path', 'original_filename_enc', 'plaintext_mac', 'nonce_prefix'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $version->toArray());
        }
    }

    // Shares and logs

    public function test_share_grantee_rules(): void
    {
        $this->assertRejected(fn () => DocumentShare::factory()->create(['grantee_type' => 'link', 'grantee_user_id' => null]), 'ds_grantee_chk');
        $this->assertRejected(fn () => DocumentShare::factory()->create(['grantee_user_id' => null]), 'ds_grantee_chk');
        $this->assertRejected(fn () => DocumentShare::factory()->toTenant($this->tenant())->create(['grantee_user_id' => User::factory()->create()->id]), 'ds_grantee_chk');
        $this->assertRejected(fn () => DocumentShare::factory()->create(['permission' => 'edit']), 'ds_permission_chk');
        $this->assertNotContains('token_hash', Schema::getColumnListing('document_shares'));
    }

    public function test_access_logs_are_append_only(): void
    {
        $document = Document::factory()->create();
        $log = DocumentAccessLog::query()->create(['document_id' => $document->id, 'action' => 'view', 'ip' => '192.0.2.1']);

        $this->assertNotNull($log->fresh()->created_at);
        $this->assertRejected(fn () => DB::table('document_access_logs')->where('id', $log->id)->update(['action' => 'download']), 'PN_APPEND_ONLY');
        $this->assertRejected(fn () => $log->delete(), 'PN_APPEND_ONLY');
        $this->assertRejected(fn () => DocumentAccessLog::query()->create(['document_id' => $document->id, 'action' => 'read']), 'dal_action_chk');
    }

    // Private storage

    public function test_document_disk_is_private_with_no_public_address(): void
    {
        $disk = config('filesystems.disks.documents');

        $this->assertSame('private', $disk['visibility']);
        $this->assertFalse($disk['serve']);
        $this->assertArrayNotHasKey('url', $disk);
        $this->assertStringStartsWith(storage_path('app/private'), $disk['root']);
        $this->assertStringNotContainsString(public_path(), $disk['root']);
        $this->assertFalse(config('filesystems.disks.local.serve'));
    }

    public function test_no_public_route_can_reach_documents_or_storage(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $this->assertSame([], $routes->filter(fn ($route) => str_starts_with($route->uri(), 'storage'))->map->uri()->values()->all());

        $documentRoutes = $routes->filter(fn ($route) => preg_match('/document|vault/i', $route->uri()) === 1);
        $this->assertNotEmpty($documentRoutes);
        foreach ($documentRoutes as $route) {
            $this->assertNotEmpty(array_intersect(['auth', 'auth:sanctum'], $route->gatherMiddleware()), $route->uri());
            $this->assertContains('verified', $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_no_storage_symlink_exists_in_the_web_root(): void
    {
        $this->assertFalse(file_exists(public_path('storage')));
    }
}
