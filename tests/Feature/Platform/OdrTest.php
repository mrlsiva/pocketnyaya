<?php

namespace Tests\Feature\Platform;

use App\Models\AdvocateProfile;
use App\Models\MediatorProfile;
use App\Models\OdrDispute;
use App\Models\OdrMediatorAssignment;
use App\Models\OdrMessage;
use App\Models\OdrOffer;
use App\Models\OdrParticipant;
use App\Models\OdrSettlement;
use App\Models\SettlementTemplate;
use App\Models\User;
use App\Notifications\OdrInvitationNotification;
use App\Services\Documents\LocalKeyProvider;
use App\Services\OdrService;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use App\Support\OdrAccess;
use App\Support\SimplePdf;
use Database\Seeders\PrivacySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Practice\PracticeTestCase;

class OdrTest extends PracticeTestCase
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrivacySeeder::class);
        Storage::fake('documents');
        (new LocalKeyProvider)->createVersion();
    }

    private function citizen(?string $email = null): User
    {
        $user = User::factory()->create($email === null ? [] : ['email' => $email]);
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

    private function dispute(User $claimant, array $answers = []): OdrDispute
    {
        $service = app(OdrService::class);

        return $service->submit($service->createDraft($claimant, ['category' => 'service', 'title' => 'Unpaid invoice', 'description' => 'Synthetic facts of the dispute.']), $answers);
    }

    /** Claimant and respondent both joined, in negotiation. */
    private function negotiation(): array
    {
        $claimant = $this->citizen();
        $respondent = $this->citizen();
        $dispute = $this->dispute($claimant);
        $this->invite($dispute, $respondent);
        app(OdrService::class)->acceptInvitation(app(OdrService::class)->participantForToken($this->token), $respondent);

        return [$dispute->fresh(), $claimant, $respondent];
    }

    private function invite(OdrDispute $dispute, User $person): void
    {
        Notification::fake();
        app(OdrService::class)->inviteRespondent($dispute, $person->name, (string) $person->email, null);
        Notification::assertSentTo(new AnonymousNotifiable, OdrInvitationNotification::class, function (OdrInvitationNotification $n) {
            $url = $n->toMail(new AnonymousNotifiable)->actionUrl;
            $this->token = basename(parse_url($url, PHP_URL_PATH));

            return true;
        });
    }

    private function mediator(OdrDispute $dispute): User
    {
        $user = $this->citizen();
        app(RoleAssignmentService::class)->assign($user, 'Mediator', (string) AccessContext::platformTenantId());
        $profile = MediatorProfile::factory()->create(['user_id' => $user->id]);
        $profile->forceFill(['verification_status' => 'verified', 'is_available' => true])->save();
        $service = app(OdrService::class);
        $service->proposeMediator($dispute, $profile, User::factory()->create());
        $service->respondToAssignment(OdrMediatorAssignment::query()->where('dispute_id', $dispute->id)->firstOrFail(), true, 'None');

        return $user;
    }

    private function template(string $status = 'reviewed', string $category = 'service'): SettlementTemplate
    {
        $template = new SettlementTemplate(['category' => $category, 'version' => random_int(1, 100000), 'title' => 'Synthetic template', 'body_template' => 'Dispute {dispute_number} between {claimant} and {respondent}. Terms: {terms}']);
        $template->forceFill(['legal_review_status' => $status])->save();

        return $template;
    }

    // Filing and screening

    public function test_filing_needs_the_disclaimer_and_records_acceptance(): void
    {
        $user = $this->citizen();
        $this->actingAs($user);
        $form = ['category' => 'service', 'title' => 'A dispute', 'description' => 'Facts.'];
        $this->post('/citizen/odr', $form)->assertSessionHasErrors('accept_disclaimer');

        $this->post('/citizen/odr', $form + ['accept_disclaimer' => '1'])->assertRedirect();
        $dispute = OdrDispute::query()->where('claimant_user_id', $user->id)->firstOrFail();
        $this->assertSame('awaiting_respondent', $dispute->status);
        $this->assertNotNull($dispute->dispute_number);
        $this->assertTrue(DB::table('disclaimer_acceptances')->where('user_id', $user->id)->exists());
        $this->get("/citizen/odr/{$dispute->id}")->assertOk()->assertSee('A dispute');
    }

    public function test_description_is_encrypted_at_rest(): void
    {
        $dispute = $this->dispute($this->citizen());
        $this->assertStringNotContainsString('Synthetic facts', (string) DB::table('odr_disputes')->where('id', $dispute->id)->value('description'));
    }

    public function test_screening_outcomes(): void
    {
        $this->assertSame('screening_failed', $this->dispute($this->citizen(), ['involves_violence' => true])->status);
        $this->assertSame('screening_failed', $this->dispute($this->citizen(), ['involves_crime' => true])->status);
        $this->assertSame('awaiting_respondent', $this->dispute($this->citizen(), ['involves_violence' => false])->status);

        $review = $this->dispute($this->citizen(), ['in_court_already' => true]);
        $this->assertSame('screening', $review->status);

        $this->staff('Compliance Officer');
        $this->get('/staff/odr')->assertOk()->assertSee($review->title);
        $this->post("/staff/odr/{$review->id}/review", ['result' => 'suitable'])->assertSessionHasErrors('note');
        $this->post("/staff/odr/{$review->id}/review", ['result' => 'suitable', 'note' => 'Reviewed.'])->assertRedirect();
        $this->assertSame('awaiting_respondent', $review->fresh()->status);
        $this->post("/staff/odr/{$review->id}/review", ['result' => 'unsuitable', 'note' => 'Again.'])->assertSessionHasErrors('status');

        $this->staff('Super Admin');
        $this->post("/staff/odr/{$review->id}/review", ['result' => 'suitable', 'note' => 'x'])->assertForbidden();
    }

    // Invitations

    public function test_respondent_token_flow_and_email_must_match(): void
    {
        $claimant = $this->citizen();
        $invited = $this->citizen();
        $dispute = $this->dispute($claimant);
        $this->invite($dispute, $invited);
        $this->assertSame(64, strlen((string) OdrParticipant::query()->where(['dispute_id' => $dispute->id, 'participant_role' => 'respondent'])->value('invitation_token_hash')));
        $this->assertNull(OdrParticipant::query()->where('invitation_token_hash', $this->token)->first());

        $this->get("/odr/invitations/{$this->token}")->assertOk()->assertSee('Sign in')->assertDontSee('Synthetic facts');
        $this->actingAs($this->citizen());
        $this->post("/odr/invitations/{$this->token}/accept")->assertForbidden();

        $this->actingAs($invited);
        $this->get("/citizen/odr/{$dispute->id}")->assertNotFound();
        $this->post("/odr/invitations/{$this->token}/accept")->assertRedirect();
        $this->get("/citizen/odr/{$dispute->id}")->assertOk();
        $this->assertSame('negotiation', $dispute->fresh()->status);
        $this->assertTrue(AccessContext::citizenCan($invited, 'odr.offers.make'));

        $this->post("/odr/invitations/{$this->token}/accept")->assertNotFound();
        $this->get("/odr/invitations/{$this->token}")->assertNotFound();
    }

    public function test_expired_invitation_and_decline(): void
    {
        $claimant = $this->citizen();
        $invited = $this->citizen();
        $dispute = $this->dispute($claimant);
        $this->invite($dispute, $invited);

        $this->travel(15)->days();
        $this->get("/odr/invitations/{$this->token}")->assertNotFound();
        $this->travelBack();

        $this->actingAs($invited)->post("/odr/invitations/{$this->token}/decline")->assertRedirect();
        $fresh = $dispute->fresh();
        $this->assertSame('closed_no_settlement', $fresh->status);
        $this->get("/citizen/odr/{$dispute->id}")->assertNotFound();
    }

    public function test_only_a_verified_advocate_can_join_as_counsel_and_colleagues_get_nothing(): void
    {
        [$dispute, $claimant] = $this->negotiation();
        $service = app(OdrService::class);
        $party = OdrAccess::participant($claimant, $dispute);

        Notification::fake();
        $lawyer = $this->citizen();
        $service->inviteCounsel($dispute, $party, 'Counsel', (string) $lawyer->email);
        $token = '';
        Notification::assertSentTo(new AnonymousNotifiable, OdrInvitationNotification::class, function (OdrInvitationNotification $n) use (&$token) {
            $token = basename(parse_url($n->toMail(new AnonymousNotifiable)->actionUrl, PHP_URL_PATH));

            return true;
        });

        $this->actingAs($lawyer);
        $this->post("/odr/invitations/{$token}/accept")->assertForbidden();

        AdvocateProfile::factory()->create(['user_id' => $lawyer->id]);
        app(RoleAssignmentService::class)->assign($lawyer, 'Verified Advocate', (string) AccessContext::platformTenantId());
        $this->post("/odr/invitations/{$token}/accept")->assertRedirect();
        $this->get("/citizen/odr/{$dispute->id}")->assertOk();

        // B3-6: another advocate, even from the same firm, has no access.
        $firm = $this->firm();
        $this->member($firm, 'Senior Associate');
        $colleague = $this->member($firm, 'Senior Associate');
        app(RoleAssignmentService::class)->assign($colleague, 'Verified Advocate', (string) AccessContext::platformTenantId());
        $this->actingAs($colleague);
        $this->get("/citizen/odr/{$dispute->id}")->assertNotFound();
    }

    // B3-1, B3-3, B3-7

    public function test_outsiders_staff_and_unaccepted_mediators_get_404(): void
    {
        [$dispute] = $this->negotiation();
        $message = app(OdrService::class)->post($dispute, $dispute->participants()->first(), null, 'Confidential message text');
        $this->assertNotNull($message);

        $this->actingAs($this->citizen());
        $this->get("/citizen/odr/{$dispute->id}")->assertNotFound();
        $this->post("/citizen/odr/{$dispute->id}/messages", ['body' => 'x'])->assertNotFound();

        $proposed = $this->citizen();
        app(RoleAssignmentService::class)->assign($proposed, 'Mediator', (string) AccessContext::platformTenantId());
        $profile = MediatorProfile::factory()->create(['user_id' => $proposed->id]);
        $profile->forceFill(['verification_status' => 'verified'])->save();
        app(OdrService::class)->proposeMediator($dispute, $profile, User::factory()->create());
        $this->actingAs($proposed);
        $this->get("/citizen/odr/{$dispute->id}")->assertNotFound();

        foreach (['Super Admin', 'Compliance Officer'] as $role) {
            $staff = $this->staff($role);
            $this->assertFalse(OdrAccess::canView($staff, $dispute));
            $this->get("/citizen/odr/{$dispute->id}")->assertNotFound();
        }
        $this->get('/staff/odr')->assertOk()->assertDontSee('Confidential message text');
    }

    public function test_messages_are_encrypted_and_append_only(): void
    {
        [$dispute, $claimant] = $this->negotiation();
        $this->actingAs($claimant)->post("/citizen/odr/{$dispute->id}/messages", ['body' => 'A private offer detail'])->assertRedirect();
        $row = DB::table('odr_messages')->where('dispute_id', $dispute->id)->first();
        $this->assertStringNotContainsString('private offer', $row->body);
        $this->assertSame(hash('sha256', 'A private offer detail'), $row->content_hash);
        $this->get("/citizen/odr/{$dispute->id}")->assertSee('A private offer detail');

        $this->expectException(QueryException::class);
        DB::table('odr_messages')->where('id', $row->id)->update(['content_hash' => str_repeat('0', 64)]);
    }

    public function test_caucus_reaches_only_the_mediator_and_that_participant(): void
    {
        [$dispute, $claimant, $respondent] = $this->negotiation();
        $mediator = $this->mediator($dispute);
        $claimantPart = OdrAccess::participant($claimant, $dispute);
        $this->assertSame('mediation', $dispute->fresh()->status);

        $this->actingAs($mediator);
        $this->post("/citizen/odr/{$dispute->id}/messages", ['body' => 'Private question for the claimant', 'private_with' => $claimantPart->id])->assertRedirect();

        $this->actingAs($claimant);
        $this->get("/citizen/odr/{$dispute->id}")->assertOk()->assertSee('Private question');
        $this->post("/citizen/odr/{$dispute->id}/messages", ['body' => 'Private reply', 'caucus' => '1'])->assertRedirect();

        $this->actingAs($respondent);
        $this->get("/citizen/odr/{$dispute->id}")->assertOk()->assertDontSee('Private question')->assertDontSee('Private reply');
        // Nobody opens a caucus but the mediator.
        $this->post("/citizen/odr/{$dispute->id}/messages", ['body' => 'Sneaky', 'caucus' => '1'])->assertForbidden();

        $this->actingAs($mediator);
        $this->get("/citizen/odr/{$dispute->id}")->assertSee('Private reply');
    }

    public function test_evidence_access_follows_the_dispute(): void
    {
        [$dispute, $claimant, $respondent] = $this->negotiation();
        $this->actingAs($claimant);
        $this->post("/citizen/odr/{$dispute->id}/evidence", ['title' => 'Receipt', 'file' => UploadedFile::fake()->createWithContent('r.pdf', '%PDF-1.4 synthetic')])->assertRedirect()->assertSessionHasNoErrors();
        $documentId = OdrMessage::query()->where('dispute_id', $dispute->id)->whereNotNull('document_id')->value('document_id');

        $url = "/citizen/odr/{$dispute->id}/documents/{$documentId}";
        $this->get($url)->assertOk();
        $this->actingAs($respondent);
        $this->get($url)->assertOk();
        $this->actingAs($this->citizen());
        $this->get($url)->assertNotFound();
        $this->staff('Compliance Officer');
        $this->get($url)->assertNotFound();
    }

    // Offers

    public function test_offers_and_counters(): void
    {
        [$dispute, $claimant, $respondent] = $this->negotiation();
        $this->actingAs($claimant);
        $this->post("/citizen/odr/{$dispute->id}/offers", ['amount' => '5000', 'terms' => 'Pay in full.'])->assertRedirect();
        $first = OdrOffer::query()->where('dispute_id', $dispute->id)->firstOrFail();
        $this->post("/citizen/odr/{$dispute->id}/offers/{$first->id}", ['decision' => 'accept'])->assertForbidden();

        $this->actingAs($respondent);
        $this->post("/citizen/odr/{$dispute->id}/offers", ['amount' => '3000', 'terms' => 'Pay part.'])->assertRedirect();
        $this->assertSame('countered', $first->fresh()->status);
        $second = OdrOffer::query()->where(['dispute_id' => $dispute->id, 'status' => 'open'])->firstOrFail();

        $this->actingAs($claimant);
        $this->post("/citizen/odr/{$dispute->id}/offers/{$first->id}", ['decision' => 'accept'])->assertForbidden();
        $this->post("/citizen/odr/{$dispute->id}/offers/{$second->id}", ['decision' => 'accept'])->assertRedirect();
        $this->assertSame('settlement_drafting', $dispute->fresh()->status);

        $this->post("/citizen/odr/{$dispute->id}/offers/{$second->id}", ['decision' => 'reject'])->assertSessionHasErrors('offer');
    }

    public function test_counsel_cannot_make_an_offer(): void
    {
        [$dispute] = $this->negotiation();
        $counsel = new OdrParticipant(['name' => 'C', 'email' => 'c@example.test']);
        $counsel->forceFill(['dispute_id' => $dispute->id, 'user_id' => User::factory()->create()->id, 'participant_role' => 'claimant_counsel', 'status' => 'joined', 'invited_at' => now()])->save();

        $this->assertThrows(fn () => app(OdrService::class)->makeOffer($dispute, $counsel, '1', 'x'), HttpException::class);
    }

    // Mediator assignment

    public function test_mediator_needs_verification_and_a_conflict_disclosure(): void
    {
        [$dispute] = $this->negotiation();
        $user = $this->citizen();
        app(RoleAssignmentService::class)->assign($user, 'Mediator', (string) AccessContext::platformTenantId());
        $profile = MediatorProfile::factory()->create(['user_id' => $user->id]);

        $this->assertThrows(fn () => app(OdrService::class)->proposeMediator($dispute, $profile, User::factory()->create()), ValidationException::class);

        $profile->forceFill(['verification_status' => 'verified', 'is_available' => true])->save();
        $this->staff('Compliance Officer');
        $this->post("/staff/odr/{$dispute->id}/assign", ['mediator_profile_id' => $profile->id])->assertRedirect();
        $this->post("/staff/odr/{$dispute->id}/assign", ['mediator_profile_id' => $profile->id])->assertSessionHasErrors('mediator_profile_id');
        $assignment = OdrMediatorAssignment::query()->where('dispute_id', $dispute->id)->firstOrFail();

        $this->actingAs($this->citizen());
        $this->post("/citizen/odr/assignments/{$assignment->id}", ['decision' => 'accept', 'conflict_disclosure' => 'None'])->assertNotFound();

        $this->actingAs($user);
        $this->get('/citizen/odr')->assertOk()->assertSee('proposed as mediator');
        $this->post("/citizen/odr/assignments/{$assignment->id}", ['decision' => 'accept'])->assertSessionHasErrors('conflict_disclosure');
        $this->post("/citizen/odr/assignments/{$assignment->id}", ['decision' => 'accept', 'conflict_disclosure' => 'None'])->assertRedirect();
        $this->get("/citizen/odr/{$dispute->id}")->assertOk();
    }

    // Settlement

    public function test_full_settlement_journey_stays_unsigned(): void
    {
        [$dispute, $claimant, $respondent] = $this->negotiation();
        $mediator = $this->mediator($dispute);
        $template = $this->template();

        $this->actingAs($claimant);
        $this->post("/citizen/odr/{$dispute->id}/settlements", ['template_id' => $template->id, 'terms' => 'X'])->assertNotFound();

        $this->actingAs($mediator);
        $this->post("/citizen/odr/{$dispute->id}/sessions", ['scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 60, 'mode' => 'video'])->assertRedirect();
        $this->post("/citizen/odr/{$dispute->id}/settlements", ['template_id' => $this->template('draft')->id, 'terms' => 'X'])->assertSessionHasErrors('template_id');
        $this->post("/citizen/odr/{$dispute->id}/settlements", ['template_id' => $this->template('reviewed', 'other')->id, 'terms' => 'X'])->assertSessionHasErrors('template_id');
        $this->post("/citizen/odr/{$dispute->id}/settlements", ['template_id' => $template->id, 'terms' => 'Respondent pays 3000 by 1 March.'])->assertRedirect();

        $settlement = OdrSettlement::query()->where('dispute_id', $dispute->id)->firstOrFail();
        $this->assertSame('settlement_pending_acceptance', $dispute->fresh()->status);
        $this->assertSame('under_review', $settlement->status);

        $this->actingAs($claimant);
        $pdf = $this->get("/citizen/odr/{$dispute->id}/documents/{$settlement->document_id}")->assertOk()->streamedContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('unsigned', $pdf);
        $this->assertStringContainsString('UNSIGNED', $pdf);
        $this->assertSame($settlement->content_hash, hash('sha256', $pdf));

        $this->get("/citizen/odr/{$dispute->id}")->assertSee('Settlement agreement (unsigned)')->assertSee('isn\'t a digital signature', false);
        $this->post("/citizen/odr/{$dispute->id}/settlements/{$settlement->id}/respond", ['decision' => 'accept'])->assertRedirect();
        $this->assertSame('under_review', $settlement->fresh()->status);
        $this->post("/citizen/odr/{$dispute->id}/settlements/{$settlement->id}/respond", ['decision' => 'accept'])->assertSessionHasErrors('status');

        $this->actingAs($respondent);
        $this->post("/citizen/odr/{$dispute->id}/settlements/{$settlement->id}/respond", ['decision' => 'accept'])->assertRedirect();
        $this->assertSame('accepted_by_all', $settlement->fresh()->status);
        $fresh = $dispute->fresh();
        $this->assertSame('settled_unsigned', $fresh->status);
        $this->assertNotSame('signed', $settlement->fresh()->status);
        $this->assertSame('electronic_acceptance', DB::table('settlement_acceptances')->where('settlement_id', $settlement->id)->value('method'));

        // B3-8: reading stays available, and writing is refused once closed.
        $this->get("/citizen/odr/{$dispute->id}")->assertOk()->assertSee('It isn\'t a court order', false);
        $this->post("/citizen/odr/{$dispute->id}/messages", ['body' => 'Too late'])->assertForbidden();

        $this->actingAs($mediator);
        $this->post("/citizen/odr/{$dispute->id}/settlements/{$settlement->id}/void")->assertSessionHasErrors('status');
    }

    public function test_mobile_api_covers_invitation_evidence_caucus_and_mediator_tools(): void
    {
        $claimant = $this->citizen();
        $respondent = $this->citizen();
        $dispute = $this->dispute($claimant);
        $this->invite($dispute, $respondent);

        Sanctum::actingAs($this->citizen(), ['api:read', 'api:write']);
        $this->postJson("/api/v1/odr/invitations/{$this->token}/accept")->assertForbidden();
        Sanctum::actingAs($respondent, ['api:read', 'api:write']);
        $this->postJson('/api/v1/odr/invitations/not-a-real-token/accept')->assertNotFound();
        $this->postJson("/api/v1/odr/invitations/{$this->token}/accept")->assertOk()->assertJsonPath('id', $dispute->id);
        $this->postJson("/api/v1/odr/disputes/{$dispute->id}/evidence", ['title' => 'Receipt', 'file' => UploadedFile::fake()->createWithContent('r.pdf', '%PDF-1.4 synthetic')])
            ->assertCreated()->assertJsonStructure(['id', 'document_id']);

        $mediator = $this->mediator($dispute->fresh());
        $claimantPart = OdrAccess::participant($claimant, $dispute);
        Sanctum::actingAs($mediator, ['api:read', 'api:write']);
        $this->getJson('/api/v1/odr/disputes')->assertOk()->assertJsonPath('data.0.id', $dispute->id);
        $this->getJson("/api/v1/odr/disputes/{$dispute->id}")->assertOk()->assertJsonPath('is_mediator', true);
        $this->postJson("/api/v1/odr/disputes/{$dispute->id}/messages", ['body' => 'Private question via API', 'private_with' => $claimantPart->id])->assertCreated();
        $this->postJson("/api/v1/odr/disputes/{$dispute->id}/sessions", ['scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 45, 'mode' => 'audio'])->assertCreated();
        $settlement = $this->postJson("/api/v1/odr/disputes/{$dispute->id}/settlements", ['template_id' => $this->template()->id, 'terms' => 'Respondent pays 2000 by 1 March.'])
            ->assertCreated()->assertJsonPath('label', 'Settlement agreement (unsigned)')->json('id');

        Sanctum::actingAs($respondent, ['api:read', 'api:write']);
        $this->getJson("/api/v1/odr/disputes/{$dispute->id}")->assertOk()->assertDontSee('Private question via API');
        $this->postJson("/api/v1/odr/disputes/{$dispute->id}/messages", ['body' => 'No caucus opened for me', 'caucus' => true])->assertForbidden();
        $this->postJson("/api/v1/odr/disputes/{$dispute->id}/settlements", ['template_id' => $this->template()->id, 'terms' => 'X'])->assertNotFound();
        $this->postJson("/api/v1/odr/disputes/{$dispute->id}/close", ['closure_reason' => 'X'])->assertNotFound();

        Sanctum::actingAs($claimant, ['api:read', 'api:write']);
        $this->getJson("/api/v1/odr/disputes/{$dispute->id}")->assertOk()->assertSee('Private question via API');
        $this->postJson("/api/v1/odr/disputes/{$dispute->id}/messages", ['body' => 'Private answer via API', 'caucus' => true])->assertCreated();

        Sanctum::actingAs($mediator, ['api:read', 'api:write']);
        $this->postJson("/api/v1/odr/disputes/{$dispute->id}/settlements/{$settlement}/void")->assertOk()->assertJsonPath('status', 'void');
        $this->postJson("/api/v1/odr/disputes/{$dispute->id}/close", ['closure_reason' => 'No agreement'])->assertOk()->assertJsonPath('status', 'closed_no_settlement');
    }

    public function test_mobile_api_lets_a_proposed_mediator_answer_with_a_disclosure(): void
    {
        [$dispute] = $this->negotiation();
        $user = $this->citizen();
        app(RoleAssignmentService::class)->assign($user, 'Mediator', (string) AccessContext::platformTenantId());
        $profile = MediatorProfile::factory()->create(['user_id' => $user->id]);
        $profile->forceFill(['verification_status' => 'verified', 'is_available' => true])->save();
        app(OdrService::class)->proposeMediator($dispute, $profile, User::factory()->create());
        $assignment = OdrMediatorAssignment::query()->where('dispute_id', $dispute->id)->firstOrFail();

        Sanctum::actingAs($this->citizen(), ['api:read', 'api:write']);
        $this->getJson('/api/v1/odr/assignments')->assertForbidden();
        $this->postJson("/api/v1/odr/assignments/{$assignment->id}", ['decision' => 'accept', 'conflict_disclosure' => 'None'])->assertNotFound();

        Sanctum::actingAs($user, ['api:read', 'api:write']);
        $this->getJson('/api/v1/odr/assignments')->assertOk()->assertJsonPath('data.0.id', $assignment->id);
        $this->postJson("/api/v1/odr/assignments/{$assignment->id}", ['decision' => 'accept'])->assertUnprocessable();
        $this->postJson("/api/v1/odr/assignments/{$assignment->id}", ['decision' => 'accept', 'conflict_disclosure' => 'No conflicts'])->assertOk()->assertJsonPath('status', 'accepted');
    }

    public function test_declining_voids_the_terms_and_a_new_version_replaces_the_old(): void
    {
        [$dispute, $claimant] = $this->negotiation();
        $mediator = $this->mediator($dispute);
        $service = app(OdrService::class);
        $template = $this->template();

        $first = $service->generateSettlement($dispute, $mediator, $template, 'First terms');
        $second = $service->generateSettlement($dispute->fresh(), $mediator, $template, 'Second terms');
        $this->assertSame('void', $first->fresh()->status);
        $this->assertSame(2, $second->version_number);

        $this->actingAs($claimant);
        $this->post("/citizen/odr/{$dispute->id}/settlements/{$second->id}/respond", ['decision' => 'decline'])->assertRedirect();
        $this->assertSame('void', $second->fresh()->status);
        $this->assertSame('mediation', $dispute->fresh()->status);
    }

    public function test_a_non_party_cannot_accept_for_someone_else(): void
    {
        [$dispute, $claimant] = $this->negotiation();
        $mediator = $this->mediator($dispute);
        $settlement = app(OdrService::class)->generateSettlement($dispute, $mediator, $this->template(), 'Terms');

        $this->actingAs($mediator);
        $this->post("/citizen/odr/{$dispute->id}/settlements/{$settlement->id}/respond", ['decision' => 'accept'])->assertNotFound();
        $this->assertSame(0, DB::table('settlement_acceptances')->where(['settlement_id' => $settlement->id, 'status' => 'accepted'])->count());
    }

    public function test_withdraw_rules(): void
    {
        [$dispute, $claimant, $respondent] = $this->negotiation();
        $this->actingAs($respondent);
        $this->post("/citizen/odr/{$dispute->id}/withdraw")->assertNotFound();
        $this->actingAs($claimant);
        $this->post("/citizen/odr/{$dispute->id}/withdraw")->assertRedirect();
        $this->assertSame('withdrawn', $dispute->fresh()->status);
    }

    public function test_templates_are_super_admin_only(): void
    {
        $this->staff('Compliance Officer');
        $this->get('/staff/odr-templates')->assertForbidden();

        $this->staff('Super Admin');
        $this->post('/staff/odr-templates', ['category' => 'service', 'title' => 'T', 'body_template' => 'Body {terms}'])->assertRedirect();
        $template = SettlementTemplate::query()->where('title', 'T')->firstOrFail();
        $this->assertSame('draft', $template->legal_review_status);
        $this->put("/staff/odr-templates/{$template->id}", ['legal_review_status' => 'reviewed'])->assertRedirect();
        $this->assertSame('reviewed', $template->fresh()->legal_review_status);
    }

    public function test_pdf_writer_makes_a_structurally_valid_file(): void
    {
        $pdf = SimplePdf::render('Title (x)', [str_repeat('Long line of words ', 400), 'Second ₹ paragraph']);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertStringContainsString('Title \\(x\\)', $pdf);
        preg_match('/startxref\n(\d+)/', $pdf, $m);
        $this->assertSame('xref', substr($pdf, (int) $m[1], 4));
    }
}
