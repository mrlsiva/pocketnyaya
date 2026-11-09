<?php

namespace Tests\Feature\Platform;

use App\Models\ConsentPurpose;
use App\Models\DataPrincipalRequest;
use App\Models\Document;
use App\Models\LegalHold;
use App\Models\RetentionRule;
use App\Models\User;
use App\Services\AuditLogWriter;
use App\Services\ConsentLedger;
use App\Services\Documents\DocumentService;
use App\Services\Documents\LocalKeyProvider;
use App\Services\ErasureService;
use App\Services\RegistrationService;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use App\Support\ConsentHash;
use Database\Seeders\PrivacySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Practice\PracticeTestCase;

class PrivacyTest extends PracticeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrivacySeeder::class);
    }

    private function citizen(): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($user);

        return $user;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $platform = (string) AccessContext::platformTenantId();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $platform, 'user_id' => $user->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($user, $role, $platform);
        $this->actingAs($user)->withSession(['mfa_passed_for' => $user->id]);

        return $user;
    }

    private function purpose(string $code): ConsentPurpose
    {
        return ConsentPurpose::query()->where('code', $code)->firstOrFail();
    }

    // MY-10 test vectors, as approved

    #[DataProvider('vectors')]
    public function test_consent_hash_matches_the_approved_vectors(array $overrides, string $expected): void
    {
        $this->assertSame($expected, ConsentHash::v3($overrides + [
            'user_id' => '0190f3a2-7b1c-7d4e-8a10-2b3c4d5e6f70',
            'sequence_no' => 1,
            'consent_purpose_id' => '0190f3a2-7b1c-7d4e-8a10-000000000001',
            'action' => 'granted',
            'channel' => 'web',
            'created_at' => '2026-11-05T10:15:30.123456Z',
            'consent_key_id' => '0190f3a2-7b1c-7d4e-8a10-00000000c0de',
            'evidence_commitment' => '3190dc8e8e0b5acdb9921ab2b96a7c051d8265698bea8989b2a7868ee4ac233a',
            'prev_hash' => null,
        ]));
    }

    public static function vectors(): array
    {
        return [
            'V3-1' => [[], 'c877c1bf33f1d1c9380279b97c2f8e7aece4a5e6036714f9c99a8f35d012d632'],
            'V3-2' => [[
                'sequence_no' => 2, 'action' => 'withdrawn', 'channel' => 'api', 'created_at' => '2026-11-05T10:20:00.000001Z',
                'evidence_commitment' => '57f93b4c1a7d20843f7be9259d871fd9f2e7b5340f2776e3ac70d41d6e541f25',
                'prev_hash' => 'c877c1bf33f1d1c9380279b97c2f8e7aece4a5e6036714f9c99a8f35d012d632',
            ], '6d12faf87cd49fa9afcac5ec882d151158cfd9b865caab5b4f5a8351661bdd99'],
            'V3-3' => [['channel' => ''], 'd7c60a0c8b48c67982ac57a871f9d5761972a7a29b04f58ca09919c27c022646'],
            'V3-4' => [['channel' => null], '80645cde30a150e092220fb3c7b276670937966b6479d7ba18d6573d0ddc581c'],
        ];
    }

    // Consent ledger

    public function test_registration_needs_consent_and_records_mandatory_purposes(): void
    {
        $form = ['name' => 'New Person', 'email' => 'new-'.Str::lower(Str::random(6)).'@example.test', 'password' => 'Secret-Pass-2026', 'password_confirmation' => 'Secret-Pass-2026'];
        $this->post('/register', $form)->assertSessionHasErrors('consent');

        $this->post('/register', $form + ['consent' => '1'])->assertRedirect();
        $user = User::query()->where('email', $form['email'])->firstOrFail();
        $ledger = app(ConsentLedger::class);
        $this->assertTrue($ledger->hasConsent($user, 'account'));
        $this->assertTrue($ledger->hasConsent($user, 'document_processing'));
        $this->assertFalse($ledger->hasConsent($user, 'ml_training'));
        $this->assertNull($ledger->firstBrokenLink($user->fresh()));
    }

    public function test_chain_links_and_detects_tampering(): void
    {
        $user = $this->citizen();
        $ledger = app(ConsentLedger::class);
        $purpose = $this->purpose('ml_training');
        $ledger->grant($user, $purpose);
        $ledger->withdraw($user, $purpose);
        $ledger->grant($user, $purpose, 'mobile');

        $rows = DB::table('consent_records')->where('user_id', $user->id)->orderBy('sequence_no')->get();
        $this->assertSame([1, 2, 3], $rows->pluck('sequence_no')->map(fn ($n) => (int) $n)->all());
        $this->assertNull($rows[0]->prev_hash);
        $this->assertSame($rows[0]->record_hash, $rows[1]->prev_hash);
        $this->assertSame($rows[1]->record_hash, $rows[2]->prev_hash);
        $this->assertNull($ledger->firstBrokenLink($user));
        $this->assertTrue($ledger->hasConsent($user, 'ml_training'));

        // A copy is altered, since the real rows refuse changes. Tests must never run DDL: it would
        // commit the test's transaction.
        $tampered = $rows->map(fn ($row) => clone $row)->all();
        $tampered[1]->action = 'granted';
        $this->assertSame(2, $ledger->firstBrokenIn($tampered));
        $removed = $rows->all();
        unset($removed[0]);
        $this->assertSame(2, $ledger->firstBrokenIn(array_values($removed)));
    }

    public function test_consent_records_are_append_only(): void
    {
        $user = $this->citizen();
        app(ConsentLedger::class)->grant($user, $this->purpose('account'));
        $row = DB::table('consent_records')->where('user_id', $user->id);

        foreach ([fn () => (clone $row)->update(['action' => 'withdrawn']), fn () => (clone $row)->delete()] as $write) {
            try {
                $write();
                $this->fail('Ledger rows must not change.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('PN_APPEND_ONLY', $e->getMessage());
            }
        }
    }

    public function test_evidence_is_encrypted_and_only_the_owner_can_read_it(): void
    {
        $user = $this->citizen();
        $ledger = app(ConsentLedger::class);
        $this->withHeader('User-Agent', 'SyntheticAgent/1.0');
        $ledger->grant($user, $this->purpose('account'));
        $record = DB::table('consent_records')->where('user_id', $user->id)->first();

        $this->assertStringNotContainsString('account', (string) base64_decode($record->evidence_encrypted, true));
        $evidence = $ledger->evidence($user->fresh(), $record);
        $this->assertSame('account', $evidence['purpose_code']);
        $this->assertNull($ledger->evidence($this->citizen(), $record));
    }

    public function test_mandatory_purpose_cannot_be_withdrawn(): void
    {
        $user = $this->citizen();
        app(ConsentLedger::class)->grant($user, $this->purpose('account'));
        $this->actingAs($user);

        $this->post('/citizen/privacy/consents/'.$this->purpose('account')->id, ['action' => 'withdraw'])->assertSessionHasErrors('purpose');
        $this->post('/citizen/privacy/consents/'.$this->purpose('ml_training')->id, ['action' => 'grant'])->assertRedirect();
        $this->post('/citizen/privacy/consents/'.$this->purpose('ml_training')->id, ['action' => 'withdraw'])->assertRedirect();
        $this->assertFalse(app(ConsentLedger::class)->hasConsent($user, 'ml_training'));
        $this->get('/citizen/privacy')->assertOk()->assertSee('Improving our models');
    }

    public function test_export_has_own_data_only_and_no_secrets(): void
    {
        $user = $this->citizen();
        $other = $this->citizen();
        app(ConsentLedger::class)->grant($other, $this->purpose('account'));

        $response = $this->actingAs($user)->get('/citizen/privacy/export')->assertOk();
        $response->assertJsonPath('account.email', $user->email)->assertJsonCount(0, 'consents');
        $text = $response->getContent();
        foreach ([$other->email, 'password', 'two_factor_secret', 'consent_key_wrapped'] as $forbidden) {
            $this->assertStringNotContainsString((string) $forbidden, (string) $text);
        }
    }

    // Requests and erasure

    public function test_request_can_be_withdrawn_only_before_processing_and_is_private(): void
    {
        $user = $this->citizen();
        $this->actingAs($user);
        $this->post('/citizen/privacy/requests', ['request_type' => 'access'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/citizen/privacy/requests', ['request_type' => 'access'])->assertSessionHasErrors('request_type');
        $request = DataPrincipalRequest::query()->where('user_id', $user->id)->firstOrFail();

        $this->actingAs($this->citizen());
        $this->post("/citizen/privacy/requests/{$request->id}/withdraw")->assertNotFound();

        $request->forceFill(['status' => 'in_review'])->save();
        $this->actingAs($user);
        $this->post("/citizen/privacy/requests/{$request->id}/withdraw")->assertStatus(422);
    }

    private function fileFor(User $user): Document
    {
        Storage::fake('documents');
        (new LocalKeyProvider)->createVersion();

        return app(DocumentService::class)->create($user, UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4 synthetic'), ['title' => 'Mine', 'category' => 'other', 'confidentiality' => 'normal'], ['scope' => 'personal']);
    }

    private function erasureRequest(User $user): DataPrincipalRequest
    {
        $request = new DataPrincipalRequest(['request_type' => 'erasure']);
        $request->forceFill(['user_id' => $user->id, 'status' => 'approved', 'due_at' => now()->addDays(30)])->save();

        return $request;
    }

    public function test_erasure_removes_personal_data_shreds_files_and_lists_what_is_kept(): void
    {
        $user = $this->citizen();
        app(ConsentLedger::class)->grant($user, $this->purpose('account'));
        $document = $this->fileFor($user);
        $version = $document->latestVersion();
        $request = $this->erasureRequest($user);
        $user->createToken('phone');

        app(ErasureService::class)->execute($request);

        $fresh = $user->fresh();
        $this->assertSame('erased', $fresh->status);
        $this->assertNull($fresh->email);
        $this->assertNull($fresh->password);
        $this->assertSame('Erased user', $fresh->name);
        $this->assertNull($fresh->consent_key_wrapped);
        $this->assertNotNull($fresh->consent_key_destroyed_at);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->assertTrue($version->fresh()->isShredded());
        $this->assertTrue($document->fresh()->trashed());

        $done = $request->fresh();
        $this->assertSame('completed', $done->status);
        $this->assertContains('audit_logs', array_column($done->retained_items, 'type'));
        $this->assertTrue(DB::table('erasure_actions')->where(['request_id' => $request->id, 'target_table' => 'users', 'action' => 'anonymized'])->exists());
        $this->assertTrue(DB::table('erasure_actions')->where(['request_id' => $request->id, 'target_table' => 'documents', 'action' => 'shredded'])->exists());

        // C44: the chain still verifies after the key is gone, and the evidence is unreadable.
        $ledger = app(ConsentLedger::class);
        $this->assertNull($ledger->firstBrokenLink($fresh));
        $this->assertNull($ledger->evidence($fresh, DB::table('consent_records')->where('user_id', $user->id)->first()));
    }

    public function test_erased_email_can_register_again_as_a_new_person(): void
    {
        $user = $this->citizen();
        $email = (string) $user->email;
        app(ErasureService::class)->execute($this->erasureRequest($user));

        $new = app(RegistrationService::class)->register(['name' => 'Return', 'email' => $email, 'password' => 'Secret-Pass-2026']);
        $this->assertNotSame($user->id, $new->id);
    }

    public function test_legal_hold_blocks_erasure_and_document_deletion(): void
    {
        $user = $this->citizen();
        $staff = User::factory()->create();
        $document = $this->fileFor($user);
        $hold = new LegalHold(['holdable_type' => 'user', 'holdable_id' => $user->id, 'reason' => 'Synthetic']);
        $hold->forceFill(['placed_by' => $staff->id, 'placed_at' => now()])->save();

        $this->assertThrows(fn () => app(ErasureService::class)->execute($this->erasureRequest($user)), ValidationException::class);
        $this->assertSame('active', $user->fresh()->status);

        $hold->forceFill(['released_at' => now()])->save();
        $docHold = new LegalHold(['holdable_type' => 'document', 'holdable_id' => $document->id, 'reason' => 'Synthetic']);
        $docHold->forceFill(['placed_by' => $staff->id, 'placed_at' => now()])->save();
        $this->actingAs($user)->delete("/citizen/vault/{$document->id}")->assertStatus(422);
        $this->assertFalse($document->fresh()->trashed());

        app(ErasureService::class)->execute($request = $this->erasureRequest($user));
        $this->assertSame('partially_fulfilled', $request->fresh()->status);
        $this->assertFalse($document->fresh()->latestVersion()->isShredded());

        $this->expectException(QueryException::class);
        $hold->fresh()->forceFill(['reason' => 'Changed'])->save();
    }

    public function test_staff_process_requests_in_order_and_cannot_process_their_own(): void
    {
        $user = $this->citizen();
        $request = new DataPrincipalRequest(['request_type' => 'access']);
        $request->forceFill(['user_id' => $user->id, 'status' => 'submitted', 'due_at' => now()->addDays(30)])->save();
        $co = $this->staff('Compliance Officer');

        $this->get('/staff/privacy/requests')->assertOk()->assertSee($user->email);
        $this->post("/staff/privacy/requests/{$request->id}", ['action' => 'complete'])->assertStatus(422);
        $this->post("/staff/privacy/requests/{$request->id}", ['action' => 'reject'])->assertSessionHasErrors('decision_reason');
        foreach (['verify_identity', 'start_review', 'approve', 'complete'] as $action) {
            $this->post("/staff/privacy/requests/{$request->id}", ['action' => $action])->assertRedirect();
        }
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertTrue(DB::table('audit_logs')->where(['auditable_id' => $request->id, 'event' => 'privacy.request_complete', 'actor_user_id' => $co->id])->exists());

        $own = new DataPrincipalRequest(['request_type' => 'access']);
        $own->forceFill(['user_id' => $co->id, 'status' => 'submitted', 'due_at' => now()->addDays(30)])->save();
        $this->post("/staff/privacy/requests/{$own->id}", ['action' => 'verify_identity'])->assertForbidden();
    }

    public function test_customers_and_super_admins_cannot_process_requests(): void
    {
        $request = new DataPrincipalRequest(['request_type' => 'access']);
        $request->forceFill(['user_id' => $this->citizen()->id, 'status' => 'submitted', 'due_at' => now()->addDays(30)])->save();

        $this->actingAs($this->citizen());
        $this->post("/staff/privacy/requests/{$request->id}", ['action' => 'verify_identity'])->assertForbidden();

        $this->staff('Super Admin');
        $this->get('/staff/privacy/requests')->assertForbidden();
    }

    public function test_retention_recorder_must_differ_from_drafter(): void
    {
        $this->staff('Compliance Officer');
        $this->post('/staff/privacy/retention', ['data_category' => 'invoices', 'retention_months' => 96, 'trigger_event' => 'financial_year_end', 'on_expiry' => 'review', 'on_erasure_request' => 'retain', 'legal_basis_note' => 'Synthetic note for testing.'])->assertRedirect();
        $rule = RetentionRule::query()->where('data_category', 'invoices')->firstOrFail();
        $this->assertSame('draft', $rule->status);
        $this->post("/staff/privacy/retention/{$rule->id}/approve", ['approval_evidence' => 'Ref 1'])->assertForbidden();

        $this->staff('Super Admin');
        $this->post("/staff/privacy/retention/{$rule->id}/approve")->assertSessionHasErrors('approval_evidence');
        $this->post("/staff/privacy/retention/{$rule->id}/approve", ['approval_evidence' => 'Counsel ref 1'])->assertRedirect();
        $this->assertSame('approved', $rule->fresh()->status);

        $this->expectException(QueryException::class);
        DB::table('retention_rules')->where('id', $rule->id)->update(['approved_by' => $rule->drafted_by]);
    }

    public function test_disclaimers_are_versioned_public_and_new_versions_win(): void
    {
        $this->get('/disclaimers/forum')->assertOk()->assertSee('Version 1');
        $this->get('/disclaimers/does_not_exist')->assertNotFound();

        $this->staff('Compliance Officer');
        $this->post('/staff/privacy/disclaimers', ['code' => 'forum', 'title' => 'Updated', 'body' => 'New wording.', 'effective_from' => now()->toDateString()])->assertRedirect();
        $this->get('/disclaimers/forum')->assertOk()->assertSee('New wording.')->assertSee('Version 2');
    }

    // Audit log

    public function test_audit_rows_get_a_hash_refuse_changes_and_anchor_daily(): void
    {
        $firm = $this->firm();
        app(AuditLogWriter::class)->record('test.event', 'thing', 'abc', $firm, null, ['status' => 'x']);
        $row = DB::table('audit_logs')->where('tenant_id', $firm)->first();
        $this->assertSame(64, strlen($row->row_hash));

        foreach ([fn () => DB::table('audit_logs')->where('id', $row->id)->update(['event' => 'changed']), fn () => DB::table('audit_logs')->where('id', $row->id)->delete()] as $write) {
            try {
                $write();
                $this->fail('Audit rows must not change.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('PN_APPEND_ONLY', $e->getMessage());
            }
        }

        $date = substr((string) $row->created_at, 0, 10);
        Artisan::call('pocketnyaya:audit-digest', ['date' => $date]);
        $digest = DB::table('audit_digests')->where(['tenant_id' => $firm, 'digest_date' => $date])->first();
        $this->assertSame(hash('sha256', $row->row_hash), $digest->root_hash);
    }

    public function test_audit_views_are_scoped_to_the_tenant_and_permission(): void
    {
        $firm = $this->firm();
        $other = $this->firm();
        app(AuditLogWriter::class)->record('mine.event', null, null, $firm);
        app(AuditLogWriter::class)->record('theirs.event', null, null, $other);
        app(AuditLogWriter::class)->record('platform.event');

        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->get('/practice/audit')->assertOk()->assertSee('mine.event')->assertDontSee('theirs.event')->assertDontSee('platform.event');

        $this->inTenant($this->member($firm, 'Senior Associate'), $firm);
        $this->get('/practice/audit')->assertForbidden();

        $this->staff('Compliance Officer');
        $this->get('/staff/audit')->assertOk()->assertSee('theirs.event')->assertSee('platform.event');
        $this->get('/staff/audit?from=2020-01-01&to=2026-12-31')->assertStatus(422);
    }
}
