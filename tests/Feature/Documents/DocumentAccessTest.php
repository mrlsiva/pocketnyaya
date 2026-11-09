<?php

namespace Tests\Feature\Documents;

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\DocumentKey;
use App\Models\DocumentShare;
use App\Models\DocumentVersion;
use App\Models\EncryptionKeyVersion;
use App\Models\Matter;
use App\Models\MediatorProfile;
use App\Models\State;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DocumentAccessTest extends DocumentTestCase
{
    /** @param array<int, mixed>|object $arguments */
    private function inspect(User $user, string $ability, array|object $arguments): Response
    {
        return Gate::forUser($user)->inspect($ability, $arguments);
    }

    private function assertStatus(?int $expected, User $user, string $ability, array|object $arguments): void
    {
        $response = $this->inspect($user, $ability, $arguments);

        if ($expected === null) {
            $this->assertTrue($response->allowed(), "{$ability} should be allowed: ".$response->message());

            return;
        }
        $this->assertTrue($response->denied(), "{$ability} should be denied");
        $this->assertSame($expected, $response->status() ?? 403, "{$ability} status");
    }

    private function fileFor(Document $document): DocumentVersion
    {
        // Only one documents key can be active at a time, so reuse it.
        $active = EncryptionKeyVersion::query()->where(['purpose' => 'documents', 'status' => 'active'])->first()
            ?? EncryptionKeyVersion::factory()->create();

        $key = DocumentKey::factory()->create([
            'document_version_id' => DocumentVersion::factory()->create(['document_id' => $document->id]),
            'kek_version_id' => $active->id,
        ]);

        return $key->version;
    }

    // Tenant documents

    public function test_member_with_permission_can_view_a_general_tenant_document(): void
    {
        $firm = $this->tenant();
        $document = Document::factory()->inTenant($firm)->create();
        $member = $this->memberOf($firm, 'Junior Associate');
        $this->context($firm);

        $this->assertStatus(null, $member, 'view', $document);
    }

    public function test_member_of_another_firm_gets_404(): void
    {
        $firmA = $this->tenant();
        $firmB = $this->tenant();
        $document = Document::factory()->inTenant($firmA)->create();
        $outsider = $this->memberOf($firmB, 'Managing Partner');

        foreach ([$firmA, $firmB, null] as $context) {
            $this->context($context);
            foreach (['view', 'download', 'update', 'delete', 'forceDelete', 'uploadVersion', 'share'] as $ability) {
                $this->assertStatus(404, $outsider, $ability, $document);
            }
        }
    }

    public function test_member_of_both_firms_sees_a_document_only_in_its_own_context(): void
    {
        $firmA = $this->tenant();
        $firmB = $this->tenant();
        $document = Document::factory()->inTenant($firmA)->create();
        $member = $this->memberOf($firmA, 'Managing Partner');
        $this->grant($member, 'Clerk', $firmB);

        $this->context($firmA);
        $this->assertStatus(null, $member, 'view', $document);
        $this->context($firmB);
        $this->assertStatus(404, $member, 'view', $document);
        $this->context(null);
        $this->assertStatus(404, $member, 'view', $document);
    }

    public function test_a_role_held_in_firm_b_gives_no_power_in_firm_a(): void
    {
        $firmA = $this->tenant();
        $firmB = $this->tenant();
        $document = Document::factory()->inTenant($firmA)->create();
        $member = $this->memberOf($firmA, 'Clerk');
        $this->grant($member, 'Managing Partner', $firmB);
        $this->context($firmA);

        $this->assertStatus(null, $member, 'view', $document);
        $this->assertStatus(403, $member, 'delete', $document);
        $this->assertStatus(403, $member, 'update', $document);
    }

    public function test_leaving_a_firm_or_a_suspended_firm_ends_access(): void
    {
        $firm = $this->tenant();
        $document = Document::factory()->inTenant($firm)->create();
        $member = $this->memberOf($firm, 'Managing Partner');
        $this->context($firm);
        $this->assertStatus(null, $member, 'view', $document);

        DB::table('tenants')->where('id', $firm)->update(['status' => 'suspended']);
        $this->assertStatus(404, $member, 'view', $document);
        DB::table('tenants')->where('id', $firm)->update(['status' => 'active']);

        DB::table('tenant_memberships')->where(['tenant_id' => $firm, 'user_id' => $member->id])->update(['status' => 'left']);
        $this->assertStatus(404, $member, 'view', $document);
    }

    public function test_being_the_owner_of_a_tenant_document_gives_no_access_after_leaving(): void
    {
        $firm = $this->tenant();
        $member = $this->memberOf($firm, 'Managing Partner');
        $document = Document::factory()->inTenant($firm)->create(['owner_user_id' => $member->id]);
        DB::table('tenant_memberships')->where(['tenant_id' => $firm, 'user_id' => $member->id])->update(['status' => 'left']);
        $this->context($firm);

        $this->assertStatus(404, $member, 'view', $document);
    }

    public function test_firm_admin_finance_manager_and_super_admin_get_no_document_content(): void
    {
        $firm = $this->tenant();
        $document = Document::factory()->inTenant($firm)->create();
        $personal = Document::factory()->create();
        $verification = $this->verificationDocument('in_review');
        $this->context($firm);

        foreach (['Firm Admin', 'Finance Manager'] as $role) {
            $this->assertStatus(404, $this->memberOf($firm, $role), 'view', $document);
        }

        $superAdmin = User::factory()->create();
        $this->grant($superAdmin, 'Super Admin', $this->platform());
        foreach ([$firm, $this->platform()] as $context) {
            $this->context($context);
            $this->assertStatus(404, $superAdmin, 'view', $document);
            $this->assertStatus(404, $superAdmin, 'view', $personal);
            $this->assertStatus(404, $superAdmin, 'view', $verification);
            $this->assertStatus(404, $superAdmin, 'delete', $verification);
        }
    }

    public function test_matter_and_client_documents_follow_who_can_see_the_parent(): void
    {
        $firm = $this->tenant();
        $partner = $this->memberOf($firm, 'Managing Partner');
        $junior = $this->memberOf($firm, 'Junior Associate');
        $client = new Client(['client_type' => 'individual', 'name' => 'Synthetic', 'state_code' => State::factory()->create()->code]);
        $client->forceFill(['tenant_id' => $firm])->save();
        $matter = new Matter(['client_id' => $client->id, 'title' => 'T', 'matter_type' => 'other', 'priority' => 'low']);
        $matter->forceFill(['tenant_id' => $firm, 'matter_number' => 'T-1', 'status' => 'open', 'opened_at' => now(), 'created_by' => $partner->id, 'metadata' => []])->save();
        $this->context($firm);

        foreach (['matter_id' => $matter->id, 'client_id' => $client->id] as $link => $id) {
            $document = Document::factory()->inTenant($firm)->create([$link => $id]);
            $this->assertStatus(null, $partner, 'view', $document);
            $this->assertStatus(404, $junior, 'view', $document);
            $this->assertStatus(404, $junior, 'download', $document);
            $this->assertStatus(404, $junior, 'uploadVersion', $document);
        }
    }

    public function test_privileged_document_needs_view_privileged_and_view_all(): void
    {
        $firm = $this->tenant();
        $document = Document::factory()->inTenant($firm)->create(['confidentiality' => 'privileged']);
        $this->context($firm);

        $this->assertStatus(null, $this->memberOf($firm, 'Managing Partner'), 'view', $document);
        $this->assertStatus(404, $this->memberOf($firm, 'Senior Associate'), 'view', $document);
        $this->assertStatus(404, $this->memberOf($firm, 'Junior Associate'), 'view', $document);
        $this->assertStatus(404, $this->memberOf($firm, 'Clerk'), 'view', $document);
    }

    public function test_tenant_document_actions_follow_each_roles_permissions(): void
    {
        $firm = $this->tenant();
        $document = Document::factory()->inTenant($firm)->create();
        $this->fileFor($document);
        $this->context($firm);

        $partner = $this->memberOf($firm, 'Managing Partner');
        $senior = $this->memberOf($firm, 'Senior Associate');
        $junior = $this->memberOf($firm, 'Junior Associate');

        $this->assertStatus(null, $partner, 'download', $document);
        $this->assertStatus(null, $partner, 'update', $document);
        $this->assertStatus(null, $partner, 'uploadVersion', $document);
        $this->assertStatus(null, $partner, 'delete', $document);
        $this->assertStatus(null, $senior, 'update', $document);
        $this->assertStatus(403, $senior, 'delete', $document);
        $this->assertStatus(null, $junior, 'download', $document);
        $this->assertStatus(403, $junior, 'update', $document);
        $this->assertStatus(403, $junior, 'uploadVersion', $document);
        $this->assertStatus(403, $junior, 'delete', $document);
    }

    public function test_creating_a_general_document_is_limited_to_the_current_tenant(): void
    {
        $firmA = $this->tenant();
        $firmB = $this->tenant();
        $partner = $this->memberOf($firmA, 'Managing Partner');
        $junior = $this->memberOf($firmA, 'Junior Associate');
        $this->context($firmA);

        $this->assertStatus(null, $partner, 'createGeneral', [Document::class, $firmA]);
        $this->assertStatus(403, $partner, 'createGeneral', [Document::class, $firmB]);
        $this->assertStatus(403, $junior, 'createGeneral', [Document::class, $firmA]);
    }

    // Personal vault

    public function test_owner_controls_their_personal_document(): void
    {
        $owner = $this->customer();
        $document = Document::factory()->create(['owner_user_id' => $owner->id]);
        $this->fileFor($document);

        foreach (['view', 'download', 'update', 'uploadVersion', 'delete'] as $ability) {
            $this->assertStatus(null, $owner, $ability, $document);
        }
        $this->assertStatus(null, $owner, 'createPersonal', [Document::class]);
    }

    public function test_nobody_else_can_reach_a_personal_document(): void
    {
        $firm = $this->tenant();
        $owner = $this->customer();
        $this->grant($owner, 'Clerk', $firm);
        $document = Document::factory()->create(['owner_user_id' => $owner->id]);
        $colleague = $this->memberOf($firm, 'Managing Partner');
        $this->context($firm);

        foreach ([$this->customer(), $colleague, $this->officer()] as $other) {
            foreach (['view', 'download', 'update', 'delete', 'uploadVersion', 'share'] as $ability) {
                $this->assertStatus(404, $other, $ability, $document);
            }
        }
    }

    public function test_personal_document_stays_personal_in_any_context(): void
    {
        $firm = $this->tenant();
        $owner = $this->customer();
        $this->grant($owner, 'Managing Partner', $firm);
        $document = Document::factory()->create(['owner_user_id' => $owner->id]);

        foreach ([$firm, $this->platform(), null] as $context) {
            $this->context($context);
            $this->assertStatus(null, $owner, 'view', $document);
        }
    }

    public function test_owner_without_the_vault_permission_gets_404(): void
    {
        $owner = User::factory()->create();
        $document = Document::factory()->create(['owner_user_id' => $owner->id]);
        $this->platform();
        $this->role('Customer');

        $this->assertStatus(404, $owner, 'view', $document);
        $this->assertStatus(403, $owner, 'createPersonal', [Document::class]);
    }

    public function test_suspended_or_erased_user_gets_nothing(): void
    {
        foreach (['suspended', 'erased', 'pending'] as $status) {
            $owner = $this->customer();
            $document = Document::factory()->create(['owner_user_id' => $owner->id]);
            $owner->forceFill(['status' => $status])->save();

            $this->assertTrue($this->inspect($owner, 'view', $document)->denied(), $status);
            $this->assertSame(0, Document::query()->visibleTo($owner)->count(), $status);
        }
    }

    // Received shares

    public function test_named_share_is_self_service_without_any_role(): void
    {
        $document = Document::factory()->create();
        $grantee = User::factory()->create();
        DocumentShare::factory()->create(['document_id' => $document->id, 'grantee_user_id' => $grantee->id]);

        $this->assertStatus(null, $grantee, 'view', $document);
        $this->assertStatus(403, $grantee, 'download', $document);
    }

    public function test_download_share_allows_download_but_never_edit_or_reshare(): void
    {
        $document = Document::factory()->create();
        $this->fileFor($document);
        $grantee = User::factory()->create();
        DocumentShare::factory()->create(['document_id' => $document->id, 'grantee_user_id' => $grantee->id, 'permission' => 'download']);

        $this->assertStatus(null, $grantee, 'download', $document);
        foreach (['update', 'delete', 'uploadVersion', 'share', 'forceDelete'] as $ability) {
            $this->assertStatus(403, $grantee, $ability, $document);
        }
    }

    public function test_the_broadest_active_share_wins(): void
    {
        $document = Document::factory()->create();
        $this->fileFor($document);
        $grantee = User::factory()->create();
        DocumentShare::factory()->create(['document_id' => $document->id, 'grantee_user_id' => $grantee->id, 'permission' => 'view']);
        DocumentShare::factory()->create(['document_id' => $document->id, 'grantee_user_id' => $grantee->id, 'permission' => 'download']);

        $this->assertStatus(null, $grantee, 'download', $document);
    }

    public function test_expired_and_revoked_shares_give_no_access(): void
    {
        $document = Document::factory()->create();
        $expired = User::factory()->create();
        $revoked = User::factory()->create();
        DocumentShare::factory()->create(['document_id' => $document->id, 'grantee_user_id' => $expired->id, 'expires_at' => now()->subMinute()]);
        DocumentShare::factory()->create(['document_id' => $document->id, 'grantee_user_id' => $revoked->id, 'revoked_at' => now()]);

        $this->assertStatus(404, $expired, 'view', $document);
        $this->assertStatus(404, $revoked, 'view', $document);
    }

    public function test_named_share_does_not_extend_to_the_grantees_colleagues(): void
    {
        $firm = $this->tenant();
        $document = Document::factory()->create();
        $grantee = $this->memberOf($firm, 'Managing Partner');
        $colleague = $this->memberOf($firm, 'Managing Partner');
        DocumentShare::factory()->create(['document_id' => $document->id, 'grantee_user_id' => $grantee->id]);
        $this->context($firm);

        $this->assertStatus(null, $grantee, 'view', $document);
        $this->assertStatus(404, $colleague, 'view', $document);
    }

    public function test_tenant_share_needs_that_tenants_context_and_permission(): void
    {
        $firm = $this->tenant();
        $other = $this->tenant();
        $document = Document::factory()->create();
        $this->fileFor($document);
        DocumentShare::factory()->toTenant($firm)->create(['document_id' => $document->id, 'permission' => 'download']);
        $partner = $this->memberOf($firm, 'Managing Partner');
        $clerk = $this->memberOf($firm, 'Clerk');
        $this->grant($partner, 'Managing Partner', $other);

        $this->context($firm);
        $this->assertStatus(null, $partner, 'view', $document);
        $this->assertStatus(null, $partner, 'download', $document);
        $this->assertStatus(404, $clerk, 'view', $document);

        $this->context($other);
        $this->assertStatus(404, $partner, 'view', $document);
        $this->context(null);
        $this->assertStatus(404, $partner, 'view', $document);
    }

    public function test_only_the_owner_passes_the_share_policy_and_the_relationship_rule_is_in_the_service(): void
    {
        $owner = $this->customer();
        $document = Document::factory()->create(['owner_user_id' => $owner->id]);

        $this->assertStatus(null, $owner, 'share', $document);
        $this->assertStatus(404, $this->customer(), 'share', $document);
    }

    public function test_revoking_a_share(): void
    {
        $owner = $this->customer();
        $personal = Document::factory()->create(['owner_user_id' => $owner->id]);
        $personalShare = DocumentShare::factory()->create(['document_id' => $personal->id, 'shared_by' => $owner->id]);
        $this->assertStatus(null, $owner, 'revoke', $personalShare);
        $this->assertStatus(404, $this->customer(), 'revoke', $personalShare);
        $this->assertStatus(404, $personalShare->granteeUser, 'revoke', $personalShare);

        $firm = $this->tenant();
        $tenantDocument = Document::factory()->inTenant($firm)->create();
        $creator = $this->memberOf($firm, 'Clerk');
        $tenantShare = DocumentShare::factory()->create(['document_id' => $tenantDocument->id, 'shared_by' => $creator->id]);
        $this->context($firm);
        $this->assertStatus(null, $creator, 'revoke', $tenantShare);
        $this->assertStatus(null, $this->memberOf($firm, 'Senior Associate'), 'revoke', $tenantShare);
        $this->assertStatus(403, $this->memberOf($firm, 'Junior Associate'), 'revoke', $tenantShare);
        $this->assertStatus(404, $this->memberOf($this->tenant(), 'Managing Partner'), 'revoke', $tenantShare);
    }

    // Verification documents

    public function test_applicant_can_view_their_own_verification_document_only(): void
    {
        $document = $this->verificationDocument();

        $this->assertStatus(null, $document->owner, 'view', $document);
        $this->assertStatus(404, $this->customer(), 'view', $document);
    }

    public function test_staff_can_read_only_after_review_starts_and_in_staff_context(): void
    {
        $officer = $this->officer();
        $firm = $this->tenant();

        $this->context($this->platform());
        foreach (['submitted', 'info_requested'] as $status) {
            $this->assertStatus(404, $officer, 'view', $this->verificationDocument($status));
        }
        foreach (['in_review', 'approved', 'rejected'] as $status) {
            $document = $this->verificationDocument($status);
            $this->fileFor2($document);
            $this->assertStatus(null, $officer, 'view', $document);
            $this->assertStatus(null, $officer, 'download', $document);
        }

        $this->context($firm);
        $this->assertStatus(404, $officer, 'view', $this->verificationDocument('in_review'));
    }

    public function test_verification_document_can_never_be_deleted(): void
    {
        $document = $this->verificationDocument('in_review');
        $officer = $this->officer();
        $this->context($this->platform());

        $this->assertStatus(403, $document->owner, 'delete', $document);
        $this->assertStatus(403, $document->owner, 'forceDelete', $document);
        $this->assertStatus(403, $officer, 'delete', $document);
        $this->assertStatus(403, $officer, 'forceDelete', $document);
        $this->assertStatus(404, $this->customer(), 'delete', $document);
    }

    public function test_verification_document_replacement_rules(): void
    {
        $open = $this->verificationDocument('info_requested');
        $this->assertStatus(null, $open->owner, 'uploadVersion', $open);
        $this->assertStatus(404, $this->customer(), 'uploadVersion', $open);

        foreach (['in_review', 'approved', 'rejected'] as $status) {
            $document = $this->verificationDocument($status);
            $this->assertStatus(422, $document->owner, 'uploadVersion', $document);
        }

        $officer = $this->officer();
        $this->context($this->platform());
        $locked = $this->verificationDocument('in_review');
        $this->assertStatus(404, $officer, 'uploadVersion', $locked);
        $this->assertStatus(403, $officer, 'update', $locked);
        $this->assertStatus(403, $locked->owner, 'update', $locked);
    }

    public function test_only_the_applicant_can_add_a_document_to_a_request(): void
    {
        $document = $this->verificationDocument();
        $request = $document->verificationRequest;

        $this->assertStatus(null, $document->owner, 'createVerification', [Document::class, $request]);
        $this->assertStatus(404, $this->customer(), 'createVerification', [Document::class, $request]);

        DB::table('verification_requests')->where('id', $request->id)->update(['status' => 'approved']);
        $this->assertStatus(422, $document->owner, 'createVerification', [Document::class, $request->fresh()]);
    }

    public function test_applicant_can_download_but_not_share_their_verification_document(): void
    {
        $document = $this->verificationDocument();
        $this->fileFor($document);

        $this->assertStatus(null, $document->owner, 'download', $document);
        $this->assertStatus(403, $document->owner, 'share', $document);
        $this->assertStatus(404, $this->customer(), 'download', $document);
    }

    public function test_mediator_applicant_controls_their_own_verification_document(): void
    {
        $applicant = $this->customer();
        $profile = MediatorProfile::factory()->create(['user_id' => $applicant->id]);
        $request = VerificationRequest::factory()->create(['advocate_profile_id' => null, 'mediator_profile_id' => $profile->id]);
        $document = Document::factory()->forVerification($request)->create();

        $this->assertSame($applicant->id, $request->applicantUserId());
        $this->assertStatus(null, $applicant, 'view', $document);
        $this->assertStatus(null, $applicant, 'uploadVersion', $document);
        $this->assertStatus(404, $this->customer(), 'view', $document);
        $this->assertContains($document->id, Document::query()->visibleTo($applicant)->pluck('id')->all());
    }

    public function test_odr_evidence_follows_the_dispute_not_the_uploader(): void
    {
        $user = $this->customer();
        $dispute = (string) Str::uuid();
        DB::table('odr_disputes')->insert(['id' => $dispute, 'claimant_user_id' => $this->customer()->id, 'category' => 'other', 'title' => 'X', 'description' => encrypt('x', false)]);
        $document = Document::factory()->create([
            'scope' => 'platform', 'owner_user_id' => $user->id, 'odr_dispute_id' => $dispute,
        ]);

        $this->assertStatus(404, $user, 'view', $document);
        $this->assertStatus(404, $user, 'uploadVersion', $document);
    }

    // Shredding, versions and keys

    public function test_download_after_shredding_returns_410_but_view_still_works(): void
    {
        $owner = $this->customer();
        $document = Document::factory()->create(['owner_user_id' => $owner->id]);
        $version = $this->fileFor($document);
        $this->assertStatus(null, $owner, 'download', $document);

        DB::table('document_keys')->where('document_version_id', $version->id)
            ->update(['wrapped_dek' => null, 'destroyed_at' => now(), 'destroyed_reason' => 'erasure']);

        $this->assertStatus(410, $owner, 'download', $document);
        $this->assertStatus(null, $owner, 'view', $document);
    }

    public function test_download_with_no_file_returns_404(): void
    {
        $owner = $this->customer();

        $this->assertStatus(404, $owner, 'download', Document::factory()->create(['owner_user_id' => $owner->id]));
    }

    public function test_the_latest_version_decides_the_download_result(): void
    {
        $owner = $this->customer();
        $document = Document::factory()->create(['owner_user_id' => $owner->id]);
        $this->fileFor($document);
        DocumentVersion::factory()->create(['document_id' => $document->id, 'version_number' => 2]);

        $this->assertSame(2, $document->latestVersion()->version_number);
        $this->assertStatus(410, $owner, 'download', $document);
    }

    public function test_soft_deleted_document_is_out_of_reach(): void
    {
        $owner = $this->customer();
        $document = Document::factory()->create(['owner_user_id' => $owner->id]);
        $grantee = User::factory()->create();
        DocumentShare::factory()->create(['document_id' => $document->id, 'grantee_user_id' => $grantee->id]);
        $document->delete();

        $this->assertStatus(404, $owner, 'view', $document);
        $this->assertStatus(404, $grantee, 'view', $document);
        $this->assertSame(0, Document::query()->visibleTo($owner)->count());
    }

    public function test_no_user_has_any_ability_on_a_key_or_version_row(): void
    {
        $key = DocumentKey::factory()->create();
        $owner = $key->version->document->owner;

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($owner)->denies($ability, $key));
            $this->assertTrue(Gate::forUser($owner)->denies($ability, $key->version));
        }
    }

    // Folders

    public function test_personal_folder_access(): void
    {
        $owner = $this->customer();
        $folder = DocumentFolder::factory()->create(['owner_user_id' => $owner->id]);

        $this->assertStatus(null, $owner, 'view', $folder);
        $this->assertStatus(null, $owner, 'update', $folder);
        $this->assertStatus(null, $owner, 'delete', $folder);
        $this->assertStatus(404, $this->customer(), 'view', $folder);
        $this->assertStatus(404, $this->customer(), 'delete', $folder);
    }

    public function test_tenant_folder_access(): void
    {
        $firm = $this->tenant();
        $folder = DocumentFolder::factory()->inTenant($firm)->create();
        $this->context($firm);

        $this->assertStatus(null, $this->memberOf($firm, 'Senior Associate'), 'update', $folder);
        $this->assertStatus(null, $this->memberOf($firm, 'Senior Associate'), 'createInTenant', [DocumentFolder::class, $firm]);
        $this->assertStatus(403, $this->memberOf($firm, 'Clerk'), 'update', $folder);
        $this->assertStatus(404, $this->memberOf($firm, 'Firm Admin'), 'view', $folder);
        $this->assertStatus(404, $this->memberOf($this->tenant(), 'Managing Partner'), 'view', $folder);
    }

    public function test_only_an_empty_folder_can_be_deleted(): void
    {
        $owner = $this->customer();
        $withDocument = DocumentFolder::factory()->create(['owner_user_id' => $owner->id]);
        Document::factory()->create(['owner_user_id' => $owner->id, 'folder_id' => $withDocument->id]);
        $withChild = DocumentFolder::factory()->create(['owner_user_id' => $owner->id]);
        DocumentFolder::factory()->create(['owner_user_id' => $owner->id, 'parent_id' => $withChild->id]);

        $this->assertStatus(422, $owner, 'delete', $withDocument);
        $this->assertStatus(422, $owner, 'delete', $withChild);
    }

    // The list scope must agree with the policy

    public function test_visible_to_matches_the_policy_for_every_user_and_document(): void
    {
        $firmA = $this->tenant();
        $firmB = $this->tenant();
        $owner = $this->customer();
        $partnerA = $this->memberOf($firmA, 'Managing Partner');
        $this->grant($partnerA, 'Customer', $this->platform());
        $juniorA = $this->memberOf($firmA, 'Junior Associate');
        $adminA = $this->memberOf($firmA, 'Firm Admin');
        $partnerB = $this->memberOf($firmB, 'Managing Partner');
        $officer = $this->officer();
        $grantee = User::factory()->create();
        $verificationOpen = $this->verificationDocument();
        $verificationReview = $this->verificationDocument('in_review');

        $general = Document::factory()->inTenant($firmA)->create();
        $personal = Document::factory()->create(['owner_user_id' => $owner->id]);
        $shared = Document::factory()->create(['owner_user_id' => $owner->id]);
        DocumentShare::factory()->create(['document_id' => $shared->id, 'grantee_user_id' => $grantee->id]);
        $sharedToFirm = Document::factory()->create(['owner_user_id' => $owner->id]);
        DocumentShare::factory()->toTenant($firmB)->create(['document_id' => $sharedToFirm->id]);

        $documents = [
            $general, $personal, $shared, $sharedToFirm, $verificationOpen, $verificationReview,
            Document::factory()->inTenant($firmA)->create(['confidentiality' => 'privileged']),
            Document::factory()->inTenant($firmB)->create(),
            Document::factory()->inTenant($firmA)->create(['confidentiality' => 'confidential']),
        ];
        $users = [
            $owner, $partnerA, $juniorA, $adminA, $partnerB, $officer, $grantee,
            $verificationOpen->owner, $verificationReview->owner,
        ];

        $checked = 0;
        foreach ([$firmA, $firmB, $this->platform(), null] as $context) {
            $this->context($context);
            foreach ($users as $user) {
                $listed = Document::query()->visibleTo($user)->pluck('id')->all();
                foreach ($documents as $document) {
                    $allowed = $this->inspect($user, 'view', $document)->allowed();
                    $this->assertSame($allowed, in_array($document->id, $listed, true), "user {$user->id} document {$document->id}");
                    $checked++;
                }
            }
        }
        $this->assertSame(324, $checked);
    }

    /** Attaches a file record to a request that has already moved past the open statuses. */
    private function fileFor2(Document $document): void
    {
        $status = DB::table('verification_requests')->where('id', $document->verification_request_id)->value('status');
        DB::table('verification_requests')->where('id', $document->verification_request_id)->update(['status' => 'submitted']);
        $this->fileFor($document);
        DB::table('verification_requests')->where('id', $document->verification_request_id)->update(['status' => $status]);
    }
}
