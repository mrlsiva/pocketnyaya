<?php

namespace Tests\Feature\Practice;

use App\Models\AdvocateProfile;
use App\Models\ConsultationMessage;
use App\Models\ConsultationRequest;
use App\Models\Document;
use App\Models\EmergencyRequest;
use App\Models\Matter;
use App\Models\MediatorProfile;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Notifications\TenantInvitationNotification;
use App\Services\ConsultationService;
use App\Services\Documents\ShareService;
use App\Services\RoleAssignmentService;
use App\Services\TeamService;
use App\Services\VerificationService;
use App\Support\AccessContext;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlatformFlowsTest extends PracticeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function platformId(): string
    {
        return (string) AccessContext::platformTenantId();
    }

    private function citizen(): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($user);

        return $user;
    }

    private function officer(): User
    {
        $officer = $this->citizen();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->platformId(), 'user_id' => $officer->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($officer, 'Compliance Officer', $this->platformId());
        $officer->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        return $officer;
    }

    private function asStaff(User $officer): static
    {
        return $this->actingAs($officer)->withSession(['mfa_passed_for' => $officer->id]);
    }

    private function advocateProfile(User $user): AdvocateProfile
    {
        $profile = new AdvocateProfile([
            'bar_council_id' => DB::table('bar_councils')->value('id'), 'enrollment_number' => 'TEST/'.Str::random(8),
            'enrollment_date' => '2015-06-15', 'city_id' => DB::table('cities')->value('id'),
        ]);
        $profile->forceFill(['user_id' => $user->id])->save();

        return $profile;
    }

    /** A verified, listed advocate with a solo practice. */
    private function listedAdvocate(): array
    {
        $tenant = $this->firm('solo');
        $user = $this->member($tenant, 'Independent Lawyer');
        app(RoleAssignmentService::class)->assignCustomer($user);
        $profile = $this->advocateProfile($user);
        $profile->forceFill(['verification_status' => 'verified', 'is_directory_listed' => true, 'verified_at' => now()])->save();
        app(RoleAssignmentService::class)->assign($user, 'Verified Advocate', $this->platformId());

        return [$user, $profile, $tenant];
    }

    // Verification

    public function test_full_verification_flow_grants_and_removes_the_verified_role(): void
    {
        $applicant = $this->citizen();
        $profile = $this->advocateProfile($applicant);
        $officer = $this->officer();
        $service = app(VerificationService::class);

        $this->assertFalse(AccessContext::citizenCan($applicant, 'directory.listing.manage_own'));

        $this->actingAs($applicant)->post('/citizen/advocate-profile/verification')->assertRedirect();
        $request = VerificationRequest::query()->where('advocate_profile_id', $profile->id)->firstOrFail();
        $this->assertSame('pending', $profile->fresh()->verification_status);

        $this->post('/citizen/advocate-profile/verification')->assertSessionHasErrors('request');

        $this->asStaff($officer)->get('/staff/verification')->assertOk()->assertSee($applicant->name);
        $this->post("/staff/verification/{$request->id}/decide", ['decision' => 'approve'])->assertSessionHasErrors('request');
        $this->post("/staff/verification/{$request->id}/start")->assertRedirect();
        $this->post("/staff/verification/{$request->id}/decide", ['decision' => 'reject'])->assertSessionHasErrors('note');
        $this->post("/staff/verification/{$request->id}/decide", ['decision' => 'approve'])->assertRedirect();

        $fresh = $profile->fresh();
        $this->assertSame('verified', $fresh->verification_status);
        $this->assertSame($officer->id, $fresh->verified_by);
        $this->assertTrue(AccessContext::citizenCan($applicant->fresh(), 'directory.listing.manage_own'));

        $service->suspendAdvocate($fresh);
        $this->assertFalse(AccessContext::citizenCan($applicant->fresh(), 'directory.listing.manage_own'));
        $this->assertFalse((bool) $profile->fresh()->is_directory_listed);
    }

    public function test_nobody_can_review_their_own_request(): void
    {
        $officer = $this->officer();
        $request = app(VerificationService::class)->submit($this->advocateProfile($officer));

        $this->asStaff($officer)->post("/staff/verification/{$request->id}/start")->assertForbidden();
    }

    public function test_only_compliance_staff_can_reach_the_queue(): void
    {
        $request = app(VerificationService::class)->submit($this->advocateProfile($this->citizen()));

        $this->actingAs($this->citizen())->get('/staff/verification')->assertForbidden();
        $this->post("/staff/verification/{$request->id}/start")->assertForbidden();

        $admin = $this->citizen();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->platformId(), 'user_id' => $admin->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($admin, 'Super Admin', $this->platformId());
        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->asStaff($admin)->get('/staff/verification')->assertForbidden();
    }

    public function test_mediator_application_uses_the_same_queue_and_grants_the_mediator_role(): void
    {
        $applicant = $this->citizen();
        $officer = $this->officer();
        $this->actingAs($applicant)->post('/citizen/mediator-application', [
            'accreditation_body' => 'Test Body', 'accreditation_number' => 'M-1', 'categories' => ['ecommerce'],
        ])->assertRedirect();

        $profile = MediatorProfile::query()->where('user_id', $applicant->id)->firstOrFail();
        $request = VerificationRequest::query()->where('mediator_profile_id', $profile->id)->firstOrFail();
        $this->assertSame($applicant->id, $request->applicantUserId());

        $service = app(VerificationService::class);
        $service->startReview($request, $officer);
        $service->decide($request->fresh(), $officer, true, null);

        $this->assertSame('verified', $profile->fresh()->verification_status);
        $this->assertTrue(AccessContext::citizenCan($applicant->fresh(), 'odr.mediate'));
    }

    public function test_enrollment_number_locks_after_verification_and_bio_returns_to_screening(): void
    {
        $user = $this->citizen();
        $profile = $this->advocateProfile($user);
        $profile->forceFill(['verification_status' => 'verified', 'bio_screening_status' => 'passed', 'bio' => 'Old'])->save();
        $original = $profile->enrollment_number;
        $city = DB::table('cities')->value('id');

        $this->actingAs($user)->put('/citizen/advocate-profile', ['city_id' => $city, 'bio' => 'New bio', 'enrollment_number' => 'HACK/1', 'bar_council_id' => 999])->assertSessionHasNoErrors();

        $fresh = $profile->fresh();
        $this->assertSame($original, $fresh->enrollment_number);
        $this->assertSame('New bio', $fresh->bio);
        $this->assertSame('pending', $fresh->bio_screening_status);
    }

    // Directory (BCI Rule 36)

    public function test_public_directory_shows_only_listed_advocates_and_biographical_fields(): void
    {
        [$listed, $profile] = $this->listedAdvocate();
        $profile->forceFill(['bio' => 'Hidden until screened', 'office_address' => 'Secret Office Address'])->save();
        $hiddenUser = $this->citizen();
        $this->advocateProfile($hiddenUser);

        $this->get('/advocates')->assertOk()->assertSee($listed->name)->assertDontSee($hiddenUser->name)->assertSee('alphabetical order');
        $page = $this->get("/advocates/{$profile->id}")->assertOk();
        $page->assertSee('Years at the Bar')->assertDontSee('Secret Office Address')->assertDontSee((string) $listed->email)->assertDontSee('Hidden until screened');

        $hidden = AdvocateProfile::query()->where('user_id', $hiddenUser->id)->firstOrFail();
        $this->get("/advocates/{$hidden->id}")->assertNotFound();

        $json = $this->getJson("/api/v1/directory/advocates/{$profile->id}")->assertOk()->json();
        $this->assertEqualsCanonicalizing(['id', 'name', 'bar_council', 'enrollment_number', 'years_at_bar', 'city', 'practice_areas', 'languages', 'qualifications', 'bio'], array_keys($json));
        $this->assertNull($json['bio']);
    }

    public function test_directory_filters_and_alphabetical_order_only(): void
    {
        [$a, $profileA] = $this->listedAdvocate();
        [$b] = $this->listedAdvocate();
        $a->forceFill(['name' => 'Zeta Test'])->save();
        $b->forceFill(['name' => 'Alpha Test'])->save();
        $area = DB::table('practice_areas')->value('id');
        $profileA->practiceAreas()->attach($area);

        $names = collect($this->getJson('/api/v1/directory/advocates')->json('data'))->pluck('name')->all();
        $this->assertSame(['Alpha Test', 'Zeta Test'], $names);

        $filtered = collect($this->getJson("/api/v1/directory/advocates?practice_area_id={$area}")->json('data'))->pluck('name')->all();
        $this->assertSame(['Zeta Test'], $filtered);
    }

    public function test_listing_needs_the_verified_role(): void
    {
        $user = $this->citizen();
        $this->advocateProfile($user);

        $this->actingAs($user)->put('/citizen/advocate-profile/listing', ['listed' => 1])->assertForbidden();
    }

    // Consultations

    public function test_full_consultation_flow_and_conversion_to_a_matter(): void
    {
        [$lawyer, $profile, $tenant] = $this->listedAdvocate();
        $customer = $this->citizen();

        $this->actingAs($customer);
        $this->post("/citizen/advocates/{$profile->id}/consult", ['mode' => 'chat', 'subject' => 'Rent dispute', 'description' => 'Synthetic details'])->assertRedirect();
        $consultation = ConsultationRequest::query()->where('customer_user_id', $customer->id)->firstOrFail();
        $this->assertSame($tenant, $consultation->tenant_id);
        $this->assertStringNotContainsString('Synthetic', (string) DB::table('consultation_requests')->where('id', $consultation->id)->value('description'));
        $this->post("/citizen/consultations/{$consultation->id}/messages", ['body' => 'Hello'])->assertRedirect();

        $this->inTenant($lawyer, $tenant);
        $this->get('/practice/consultations')->assertOk()->assertSee('Rent dispute');
        $this->post("/practice/consultations/{$consultation->id}/convert")->assertSessionHasErrors('status');
        $this->post("/practice/consultations/{$consultation->id}/respond", ['status' => 'completed'])->assertSessionHasErrors('status');
        $this->post("/practice/consultations/{$consultation->id}/respond", ['status' => 'accepted'])->assertRedirect();
        $this->put("/practice/consultations/{$consultation->id}/notes", ['lawyer_private_notes' => 'For my eyes only'])->assertRedirect();

        $this->post("/practice/consultations/{$consultation->id}/convert")->assertRedirect();
        $matter = Matter::query()->where('tenant_id', $tenant)->firstOrFail();
        $this->assertSame('Rent dispute', $matter->title);
        $this->assertSame($customer->id, DB::table('clients')->where('id', $matter->client_id)->value('customer_user_id'));

        // The customer never sees the lawyer's private notes.
        $this->actingAs($customer);
        setPermissionsTeamId(null);
        $this->get("/citizen/consultations/{$consultation->id}")->assertOk()->assertDontSee('For my eyes only');
    }

    public function test_consultation_is_only_visible_to_its_two_sides(): void
    {
        [, $profile, $tenant] = $this->listedAdvocate();
        $customer = $this->citizen();
        $consultation = app(ConsultationService::class)->request($customer, $profile, ['mode' => 'chat', 'subject' => 'Private', 'description' => 'X']);

        $this->actingAs($this->citizen())->get("/citizen/consultations/{$consultation->id}")->assertNotFound();
        $this->post("/citizen/consultations/{$consultation->id}/messages", ['body' => 'x'])->assertNotFound();

        $other = $this->firm();
        $this->inTenant($this->member($other, 'Managing Partner'), $other);
        $this->get("/practice/consultations/{$consultation->id}")->assertNotFound();

        // A firm colleague who isn't the addressee sees it only with view_all.
        $firm = $this->firm();
        $addressee = $this->member($firm, 'Junior Associate');
        app(RoleAssignmentService::class)->assignCustomer($addressee);
        $firmProfile = $this->advocateProfile($addressee);
        $firmProfile->forceFill(['verification_status' => 'verified', 'is_directory_listed' => true])->save();
        app(RoleAssignmentService::class)->assign($addressee, 'Verified Advocate', $this->platformId());
        $firmConsultation = app(ConsultationService::class)->request($customer, $firmProfile, ['mode' => 'chat', 'subject' => 'S', 'description' => 'D']);

        $this->inTenant($this->member($firm, 'Senior Associate'), $firm);
        $this->get("/practice/consultations/{$firmConsultation->id}")->assertNotFound();
        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->get("/practice/consultations/{$firmConsultation->id}")->assertOk();
    }

    public function test_consultation_needs_a_verified_listed_advocate(): void
    {
        $user = $this->citizen();
        $unlisted = $this->advocateProfile($this->citizen());

        $this->actingAs($user)->post("/citizen/advocates/{$unlisted->id}/consult", ['mode' => 'chat', 'subject' => 'X', 'description' => 'Y'])->assertNotFound();

        [$lawyer, $profile] = $this->listedAdvocate();
        $this->actingAs($lawyer);
        $this->expectException(ValidationException::class);
        app(ConsultationService::class)->request($lawyer, $profile, ['mode' => 'chat', 'subject' => 'Self', 'description' => 'Y']);
    }

    public function test_messages_are_append_only_and_stop_after_cancellation(): void
    {
        [, $profile] = $this->listedAdvocate();
        $customer = $this->citizen();
        $service = app(ConsultationService::class);
        $consultation = $service->request($customer, $profile, ['mode' => 'chat', 'subject' => 'S', 'description' => 'D']);
        $message = $service->message($consultation, $customer, 'Secret message');

        $this->assertStringNotContainsString('Secret', (string) DB::table('consultation_messages')->where('id', $message->id)->value('body'));
        foreach ([fn () => DB::table('consultation_messages')->where('id', $message->id)->update(['sender_user_id' => User::factory()->create()->id]), fn () => $message->delete()] as $write) {
            try {
                $write();
                $this->fail('Expected append-only rejection.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('PN_APPEND_ONLY', $e->getMessage());
            }
        }

        $this->actingAs($customer)->post("/citizen/consultations/{$consultation->id}/cancel")->assertRedirect();
        $this->post("/citizen/consultations/{$consultation->id}/messages", ['body' => 'x'])->assertSessionHasErrors('body');
        $this->assertSame(1, ConsultationMessage::query()->where('consultation_request_id', $consultation->id)->count());
    }

    public function test_api_consultation_flow(): void
    {
        [, $profile] = $this->listedAdvocate();
        $customer = $this->citizen();
        Sanctum::actingAs($customer, ['api:read', 'api:write']);

        $id = $this->postJson('/api/v1/consultations', ['advocate_id' => $profile->id, 'mode' => 'chat', 'subject' => 'S', 'description' => 'D'])->assertCreated()->json('id');
        $this->postJson("/api/v1/consultations/{$id}/messages", ['body' => 'Hi'])->assertCreated();
        $this->getJson("/api/v1/consultations/{$id}")->assertOk()->assertJsonPath('messages.0.body', 'Hi')->assertJsonPath('messages.0.mine', true)->assertJsonMissingPath('lawyer_private_notes');

        Sanctum::actingAs($this->citizen(), ['api:read', 'api:write']);
        $this->getJson("/api/v1/consultations/{$id}")->assertNotFound();
    }

    // Sharing (DA-28)

    public function test_vault_share_needs_a_real_relationship(): void
    {
        [$lawyer, $profile, $tenant] = $this->listedAdvocate();
        $owner = $this->citizen();
        $document = Document::factory()->create(['owner_user_id' => $owner->id]);
        $shares = app(ShareService::class);

        foreach ([fn () => $shares->shareWithUser($document, $owner, $lawyer, 'view', null), fn () => $shares->shareWithTenant($document, $owner, $tenant, 'view', null)] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected the share to be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('recipient', $e->errors());
            }
        }

        $service = app(ConsultationService::class);
        $consultation = $service->request($owner, $profile, ['mode' => 'chat', 'subject' => 'S', 'description' => 'D']);
        $service->respond($consultation, 'accepted');

        $this->assertSame($lawyer->id, $shares->shareWithUser($document, $owner, $lawyer, 'download', null)->grantee_user_id);
        $this->assertSame($tenant, $shares->shareWithTenant($document, $owner, $tenant, 'view', null)->grantee_tenant_id);
    }

    public function test_only_the_owner_can_share_a_vault_document(): void
    {
        [$lawyer] = $this->listedAdvocate();
        $document = Document::factory()->create(['owner_user_id' => $this->citizen()->id]);

        $this->expectException(HttpException::class);
        app(ShareService::class)->shareWithUser($document, $this->citizen(), $lawyer, 'view', null);
    }

    public function test_tenant_document_shares_only_with_the_clients_linked_user(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $customer = $this->citizen();
        $client = $this->client($firm);
        $client->forceFill(['customer_user_id' => $customer->id])->save();
        $document = Document::factory()->inTenant($firm)->create(['client_id' => $client->id, 'owner_user_id' => $partner->id]);
        setPermissionsTeamId($firm);
        $shares = app(ShareService::class);

        $this->assertSame($customer->id, $shares->shareWithUser($document, $partner, $customer, 'view', null)->grantee_user_id);

        $this->expectException(ValidationException::class);
        $shares->shareWithUser($document, $partner, $this->citizen(), 'view', null);
    }

    // Emergency

    public function test_emergency_request_is_private_encrypted_and_reaches_the_triage_queue(): void
    {
        $customer = $this->citizen();
        $this->actingAs($customer)->post('/citizen/emergency', [
            'category' => 'domestic_violence', 'city_id' => DB::table('cities')->value('id'), 'contact_phone' => '9000000000', 'description' => 'Synthetic urgent details',
        ])->assertRedirect()->assertSessionHas('status');

        $request = EmergencyRequest::query()->where('customer_user_id', $customer->id)->firstOrFail();
        $this->assertSame('p1', $request->priority);
        $this->assertEqualsWithDelta(30, now()->diffInMinutes($request->sla_due_at), 1);
        $raw = DB::table('emergency_requests')->where('id', $request->id)->first();
        $this->assertStringNotContainsString('Synthetic', (string) $raw->description);
        $this->assertStringNotContainsString('9000000000', (string) $raw->contact_phone);

        $officer = $this->officer();
        $this->asStaff($officer)->get('/staff/emergency')->assertOk()->assertSee('Synthetic urgent details');
        $this->post("/staff/emergency/{$request->id}/triage", ['status' => 'assigned'])->assertSessionHasErrors('assigned_responder_id');
        $this->post("/staff/emergency/{$request->id}/triage", ['status' => 'acknowledged'])->assertRedirect();
        $this->assertNotNull($request->fresh()->acknowledged_at);

        $this->actingAs($this->citizen())->get('/staff/emergency')->assertForbidden();
    }

    public function test_guests_cannot_submit_emergency_requests(): void
    {
        $this->post('/citizen/emergency', [])->assertRedirect(route('login'));
    }

    public function test_assigned_responder_sees_only_their_own_requests(): void
    {
        [$lawyer, $profile] = $this->listedAdvocate();
        $this->actingAs($lawyer)->put('/citizen/advocate-profile/responder', ['contact_phone' => '9111111111', 'active' => 1])->assertRedirect();
        $responderId = DB::table('emergency_responders')->where('advocate_profile_id', $profile->id)->value('id');

        $request = new EmergencyRequest(['category' => 'other', 'city_id' => DB::table('cities')->value('id'), 'contact_phone' => '9', 'description' => 'Assigned case']);
        $request->forceFill(['status' => 'assigned', 'assigned_responder_id' => $responderId, 'sla_due_at' => now()])->save();
        $other = new EmergencyRequest(['category' => 'other', 'city_id' => DB::table('cities')->value('id'), 'contact_phone' => '9', 'description' => 'Other case']);
        $other->forceFill(['status' => 'open', 'sla_due_at' => now()])->save();

        $this->get('/citizen/emergency/assigned')->assertOk()->assertSee('Assigned case')->assertDontSee('Other case');
        $this->actingAs($this->citizen())->get('/citizen/emergency/assigned')->assertForbidden();
    }

    // Team

    public function test_invitation_flow_and_email_binding(): void
    {
        Notification::fake();
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $invitee = $this->citizen();
        setPermissionsTeamId($firm);

        $token = app(TeamService::class)->invite($partner, $firm, (string) $invitee->email, 'Clerk / Office Staff');
        Notification::assertSentOnDemand(TenantInvitationNotification::class);
        $this->assertSame(hash('sha256', $token), DB::table('tenant_invitations')->value('token_hash'));

        $this->get("/invitations/{$token}")->assertOk();
        $this->actingAs($this->citizen())->post("/invitations/{$token}/accept")->assertSessionHasErrors('invitation');

        $this->actingAs($invitee)->post("/invitations/{$token}/accept")->assertRedirect(route('practice.dashboard'));
        $this->assertTrue(AccessContext::isActiveMember($invitee, $firm));
        setPermissionsTeamId($firm);
        $this->assertTrue($invitee->fresh()->hasRole('Clerk / Office Staff'));

        $this->post("/invitations/{$token}/accept")->assertStatus(410);
    }

    public function test_invitation_rules(): void
    {
        Notification::fake();
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $admin = $this->member($firm, 'Firm Admin');
        $team = app(TeamService::class);
        setPermissionsTeamId($firm);

        $team->invite($partner, $firm, 'one@example.test', 'Clerk / Office Staff');
        $this->assertThrows(fn () => $team->invite($partner, $firm, 'ONE@example.test', 'Clerk / Office Staff'), ValidationException::class);
        $this->assertThrows(fn () => $team->invite($admin, $firm, 'two@example.test', 'Managing Partner'), HttpException::class);
        $this->assertThrows(fn () => $team->invite($partner, $firm, 'three@example.test', 'Super Admin'), ValidationException::class);

        $solo = $this->firm('solo');
        $lawyer = $this->member($solo, 'Independent Lawyer');
        setPermissionsTeamId($solo);
        $this->assertThrows(fn () => $team->invite($lawyer, $solo, 'x@example.test', 'Clerk / Office Staff'), HttpException::class);
    }

    public function test_expired_or_revoked_invitations_fail_and_inviter_is_rechecked(): void
    {
        Notification::fake();
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $invitee = $this->citizen();
        setPermissionsTeamId($firm);
        $team = app(TeamService::class);

        $token = $team->invite($partner, $firm, (string) $invitee->email, 'Clerk / Office Staff');
        DB::table('tenant_memberships')->where(['tenant_id' => $firm, 'user_id' => $partner->id])->update(['status' => 'left']);
        $this->actingAs($invitee)->post("/invitations/{$token}/accept")->assertSessionHasErrors('invitation');
        DB::table('tenant_memberships')->where(['tenant_id' => $firm, 'user_id' => $partner->id])->update(['status' => 'active']);
        app(RoleAssignmentService::class)->assign($partner, 'Managing Partner', $firm);

        DB::table('tenant_invitations')->update(['expires_at' => now()->subDay()]);
        $this->post("/invitations/{$token}/accept")->assertStatus(410);
        $this->get("/invitations/{$token}")->assertStatus(410);
    }

    public function test_role_rules_no_self_elevation_and_advocate_profile_requirement(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $admin = $this->member($firm, 'Firm Admin');
        $clerk = $this->member($firm, 'Clerk / Office Staff');
        $team = app(TeamService::class);
        setPermissionsTeamId($firm);

        $this->assertThrows(fn () => $team->assignRole($partner, $firm, $partner, 'Firm Admin'), HttpException::class);
        $this->assertThrows(fn () => $team->assignRole($admin, $firm, $clerk, 'Firm Admin'), HttpException::class);
        $this->assertThrows(fn () => $team->assignRole($partner, $firm, $clerk, 'Junior Associate'), ValidationException::class);

        $this->advocateProfile($clerk);
        $team->assignRole($admin, $firm, $clerk, 'Junior Associate');
        setPermissionsTeamId($firm);
        $this->assertTrue($clerk->fresh()->hasRole('Junior Associate'));
    }

    public function test_removal_rules_keep_a_partner_and_revoke_access(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $admin = $this->member($firm, 'Firm Admin');
        $clerk = $this->member($firm, 'Clerk / Office Staff');
        $team = app(TeamService::class);
        setPermissionsTeamId($firm);

        $this->assertThrows(fn () => $team->deactivate($admin, $firm, $partner), HttpException::class);
        $this->assertThrows(fn () => $team->deactivate($partner, $firm, $partner), ValidationException::class);

        $team->deactivate($admin, $firm, $clerk);
        $this->assertFalse(AccessContext::isActiveMember($clerk, $firm));
        $this->assertSame(0, DB::table('model_has_roles')->where(['model_id' => $clerk->id, 'team_id' => $firm])->count());

        $this->inTenant($clerk, $firm);
        $this->get('/practice')->assertRedirect(route('workspaces.index'));
    }

    public function test_team_screen_is_limited_to_the_firm(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $outsider = $this->member($this->firm(), 'Clerk / Office Staff');

        $this->inTenant($partner, $firm);
        $this->get('/practice/team')->assertOk()->assertSee($partner->name)->assertDontSee($outsider->name);
        $this->delete("/practice/team/{$outsider->id}")->assertNotFound();
    }
}
