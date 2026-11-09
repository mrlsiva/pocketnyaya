<?php

namespace Tests\Feature\Platform;

use App\Models\AdvocateProfile;
use App\Models\ConsentPurpose;
use App\Models\Document;
use App\Models\TaxRule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ConsentLedger;
use App\Services\Documents\DocumentService;
use App\Services\Documents\LocalKeyProvider;
use App\Services\MatterService;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use Database\Seeders\PrivacySeeder;
use Database\Seeders\ScreeningRuleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Practice\PracticeTestCase;

class AdminAndApiTest extends PracticeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PrivacySeeder::class, ScreeningRuleSeeder::class]);
    }

    private function citizen(array $abilities = ['api:read', 'api:write']): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($user);
        Sanctum::actingAs($user, $abilities);

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

    private function apiInTenant(User $user, string $tenant, array $abilities = ['api:read', 'api:write']): static
    {
        Sanctum::actingAs($user, $abilities);

        return $this->withHeader('X-Tenant-ID', $tenant);
    }

    // Admin

    public function test_suspending_a_practice_is_audited_and_ends_access(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');

        $this->staff('Super Admin');
        $this->get('/staff/admin/tenants')->assertOk()->assertSee('Firm '.$firm);
        $this->put("/staff/admin/tenants/{$firm}", ['status' => 'suspended'])->assertSessionHasErrors('reason');
        $this->put("/staff/admin/tenants/{$firm}", ['status' => 'suspended', 'reason' => 'Synthetic'])->assertRedirect();
        $this->assertSame('suspended', Tenant::query()->findOrFail($firm)->status);
        $this->assertTrue(DB::table('audit_logs')->where(['event' => 'platform.tenant_status_changed', 'auditable_id' => $firm])->exists());

        $this->assertFalse(AccessContext::isActiveMember($partner, $firm));
    }

    public function test_closing_a_practice_stays_off_until_offboarding_is_defined(): void
    {
        $firm = $this->firm();
        $this->staff('Super Admin');
        $this->put("/staff/admin/tenants/{$firm}", ['status' => 'closed', 'reason' => 'x'])->assertSessionHasErrors('status');
    }

    public function test_every_staff_page_renders_for_the_roles_that_hold_it(): void
    {
        $pages = ['/staff', '/staff/verification', '/staff/tax-rules', '/staff/emergency', '/staff/moderation', '/staff/screening-rules', '/staff/privacy/requests', '/staff/privacy/retention', '/staff/privacy/holds', '/staff/privacy/breaches', '/staff/privacy/policies', '/staff/audit', '/staff/odr', '/staff/odr-templates', '/staff/admin/tenants', '/staff/admin/users', '/staff/admin/settings', '/staff/admin/plans', '/staff/admin/reference'];
        $seen = [];
        foreach (['Super Admin', 'Compliance Officer'] as $role) {
            $this->staff($role);
            foreach ($pages as $page) {
                $status = $this->get($page)->getStatusCode();
                $this->assertContains($status, [200, 403], "{$role} {$page} returned {$status}");
                $seen[$page] = ($seen[$page] ?? false) || $status === 200;
            }
        }
        $this->assertSame([], array_keys(array_filter($seen, fn ($ok) => ! $ok)), 'Pages no staff role can open');
    }

    public function test_the_platform_tenant_cannot_be_changed(): void
    {
        $this->staff('Super Admin');
        $this->put('/staff/admin/tenants/'.AccessContext::platformTenantId(), ['status' => 'suspended', 'reason' => 'x'])->assertNotFound();
    }

    public function test_admin_views_show_no_firm_content(): void
    {
        $firm = $this->firm();
        $this->client($firm, 'Very Private Client');
        $this->staff('Super Admin');
        $this->get('/staff/admin/tenants')->assertOk()->assertDontSee('Very Private Client');
        $this->get('/staff/admin/reference')->assertOk();
        $this->get('/staff/admin/settings')->assertOk();
        $this->get('/staff/admin/plans')->assertOk();
    }

    public function test_suspending_a_user_revokes_tokens_and_cannot_target_self(): void
    {
        $person = User::factory()->create();
        $person->createToken('phone');
        $admin = $this->staff('Super Admin');

        $this->put("/staff/admin/users/{$admin->id}", ['status' => 'suspended', 'reason' => 'x'])->assertForbidden();
        $this->put("/staff/admin/users/{$person->id}", ['status' => 'suspended', 'reason' => 'Synthetic'])->assertRedirect();
        $this->assertSame('suspended', $person->fresh()->status);
        $this->assertSame(0, $person->tokens()->count());
    }

    public function test_compliance_officer_can_view_but_not_change(): void
    {
        $firm = $this->firm();
        $person = User::factory()->create();
        $this->staff('Compliance Officer');

        $this->get('/staff/admin/tenants')->assertOk();
        $this->get('/staff/admin/users')->assertOk();
        $this->put("/staff/admin/tenants/{$firm}", ['status' => 'suspended', 'reason' => 'x'])->assertForbidden();
        $this->put("/staff/admin/users/{$person->id}", ['status' => 'suspended', 'reason' => 'x'])->assertForbidden();
        $this->get('/staff/admin/settings')->assertForbidden();
    }

    public function test_customers_cannot_reach_admin(): void
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($user);
        $this->actingAs($user)->get('/staff/admin/tenants')->assertForbidden();
    }

    public function test_settings_plans_and_reference_data_can_be_managed(): void
    {
        $this->staff('Super Admin');
        $this->put('/staff/admin/settings', ['key' => 'Bad Key', 'value' => 'x'])->assertSessionHasErrors('key');
        $this->put('/staff/admin/settings', ['key' => 'support.hours', 'value' => '9 to 5'])->assertRedirect();
        $this->get('/staff/admin/settings')->assertSee('9 to 5');

        $this->post('/staff/admin/plans', ['code' => 'starter_test', 'name' => 'Starter', 'billing_period' => 'monthly', 'price' => '999'])->assertRedirect();
        $plan = DB::table('subscription_plans')->where('code', 'starter_test')->first();
        $this->post("/staff/admin/plans/{$plan->id}/toggle")->assertRedirect();
        $this->assertSame(0, (int) DB::table('subscription_plans')->where('id', $plan->id)->value('is_active'));

        $this->post('/staff/admin/reference/practice-area', ['name' => 'Synthetic Law '.Str::random(5)])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/staff/admin/reference/unknown', ['name' => 'X'])->assertNotFound();
    }

    // Practice settings

    public function test_practice_settings_permissions(): void
    {
        $firm = $this->firm();
        $form = ['name' => 'Renamed Firm', 'gst_registration_type' => 'regular', 'gstin' => '33ABCDE1234F1Z5', 'state_code' => '33'];

        $this->inTenant($this->member($firm, 'Senior Associate'), $firm);
        $this->get('/practice/settings')->assertOk();
        $this->put('/practice/settings', $form)->assertForbidden();
        $this->post('/practice/settings/availability', ['starts_on' => '2027-01-01', 'ends_on' => '2027-01-02', 'availability' => 'leave'])->assertRedirect();

        $this->inTenant($this->member($firm, 'Firm Admin'), $firm);
        $this->put('/practice/settings', ['gstin' => 'BAD'] + $form)->assertSessionHasErrors('gstin');
        $this->put('/practice/settings', ['gstin' => null] + $form)->assertSessionHasErrors('gstin');
        $this->put('/practice/settings', $form)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Renamed Firm', Tenant::query()->findOrFail($firm)->name);
        $this->post('/practice/settings/groups', ['name' => 'Civil'])->assertRedirect();

        $this->inTenant($this->member($firm, 'Finance Manager'), $firm);
        $this->post('/practice/settings/groups', ['name' => 'Tax'])->assertForbidden();
    }

    // DA-28 sharing

    public function test_vault_sharing_needs_a_relationship(): void
    {
        Storage::fake('documents');
        (new LocalKeyProvider)->createVersion();
        $owner = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($owner);
        $stranger = User::factory()->create();
        $document = app(DocumentService::class)->create($owner, UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4 x'), ['title' => 'Mine', 'category' => 'other', 'confidentiality' => 'normal'], ['scope' => 'personal']);

        $this->actingAs($owner);
        $this->post("/citizen/vault/{$document->id}/shares", ['recipient_id' => $stranger->id, 'permission' => 'view'])->assertSessionHasErrors('recipient');

        $firm = $this->firm();
        $advocate = $this->member($firm, 'Independent Lawyer');
        $profile = AdvocateProfile::factory()->create(['user_id' => $advocate->id]);
        DB::table('consultation_requests')->insert([
            'id' => (string) Str::uuid(), 'customer_user_id' => $owner->id, 'advocate_profile_id' => $profile->id, 'tenant_id' => $firm, 'advocate_membership_id' => $this->membership($firm, $advocate), 'mode' => 'chat',
            'subject' => 'x', 'description' => encrypt('x', false), 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get("/citizen/vault/{$document->id}")->assertOk()->assertSee($advocate->name);
        $this->post("/citizen/vault/{$document->id}/shares", ['recipient_id' => $advocate->id, 'permission' => 'edit'])->assertSessionHasErrors('permission');
        $this->post("/citizen/vault/{$document->id}/shares", ['recipient_id' => $advocate->id, 'permission' => 'view'])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($advocate);
        $this->get("/citizen/vault/{$document->id}")->assertOk();
        $this->post("/citizen/vault/{$document->id}/shares", ['recipient_id' => $owner->id, 'permission' => 'view'])->assertForbidden();
    }

    // API: every write needs api:write, and tenant data needs a tenant header

    public function test_read_only_tokens_cannot_write_anywhere_new(): void
    {
        $this->citizen(['api:read']);

        $this->getJson('/api/v1/forum/questions')->assertOk();
        $this->postJson('/api/v1/forum/questions', ['title' => 'A long enough title here', 'body' => 'A long enough body for the question.'])->assertForbidden();
        $this->postJson('/api/v1/odr/disputes', [])->assertForbidden();
        $this->postJson('/api/v1/privacy/requests', ['request_type' => 'access'])->assertForbidden();
        $this->postJson('/api/v1/me/devices', [])->assertForbidden();
    }

    public function test_api_requires_authentication_except_public_forum(): void
    {
        $this->getJson('/api/v1/forum/questions')->assertOk();
        foreach (['/api/v1/vault/documents', '/api/v1/odr/disputes', '/api/v1/privacy/consents', '/api/v1/invoices'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_tenant_api_needs_the_header_and_membership(): void
    {
        $firm = $this->firm();
        $finance = $this->member($firm, 'Finance Manager');

        Sanctum::actingAs($finance, ['api:read', 'api:write']);
        $this->getJson('/api/v1/invoices')->assertStatus(400);
        $this->withHeader('X-Tenant-ID', $this->firm())->getJson('/api/v1/invoices')->assertForbidden();
    }

    public function test_billing_api_journey_and_redaction(): void
    {
        $rule = new TaxRule(['code' => 'API_TEST', 'name' => 'X', 'supplier_registration' => 'any', 'recipient_type' => 'any', 'recipient_turnover' => 'any', 'supply_type' => 'any', 'charge_mechanism' => 'forward', 'cgst_rate' => '9', 'sgst_rate' => '9', 'igst_rate' => '0', 'invoice_type' => 'tax_invoice', 'effective_from' => '2020-01-01']);
        $rule->forceFill(['approved_at' => now(), 'is_active' => true])->save();

        $firm = $this->firm();
        $client = $this->client($firm);
        $matter = $this->matter($firm, $client);
        $finance = $this->member($firm, 'Finance Manager');
        $this->apiInTenant($finance, $firm);

        $invoice = $this->postJson('/api/v1/invoices', ['client_id' => $client->id, 'matter_id' => $matter->id])->assertCreated()->json();
        $this->assertNull($invoice['invoice_number']);
        $this->postJson("/api/v1/invoices/{$invoice['id']}/lines", ['line_type' => 'professional_fee', 'description' => 'Hidden text here', 'quantity' => 1, 'unit_price' => 1000])->assertCreated()->assertJsonPath('subtotal', '1000.00');
        $issued = $this->postJson("/api/v1/invoices/{$invoice['id']}/issue")->assertOk()->json();
        $this->assertSame('1180.00', $issued['total_amount']);
        $this->getJson("/api/v1/invoices/{$invoice['id']}")->assertOk()->assertJsonPath('redacted', false)->assertJsonPath('lines.0.description', 'Hidden text here');

        app(MatterService::class)->setBillingRestriction($matter, true);
        $this->getJson("/api/v1/invoices/{$invoice['id']}")->assertOk()->assertJsonPath('redacted', true)->assertJsonPath('lines.0.description', null)->assertJsonPath('matter_id', null);
        $this->postJson("/api/v1/invoices/{$invoice['id']}/payments", ['amount' => 100, 'payment_date' => now()->toDateString(), 'method' => 'upi'])->assertCreated()->assertJsonPath('status', 'partially_paid');

        $other = $this->firm();
        $this->apiInTenant($this->member($other, 'Finance Manager'), $other);
        $this->getJson("/api/v1/invoices/{$invoice['id']}")->assertNotFound();
    }

    public function test_associate_cannot_use_billing_api_but_can_log_time(): void
    {
        $firm = $this->firm();
        $junior = $this->member($firm, 'Junior Associate');
        $matter = $this->matter($firm);
        $this->assign($matter, $junior);
        $this->apiInTenant($junior, $firm);

        $this->getJson('/api/v1/invoices')->assertForbidden();
        $this->postJson('/api/v1/time-entries', ['matter_id' => $matter->id, 'work_date' => now()->toDateString(), 'minutes' => 30, 'description' => 'Work'])->assertCreated();
        $this->getJson('/api/v1/time-entries')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/time-entries', ['matter_id' => $this->matter($firm)->id, 'work_date' => now()->toDateString(), 'minutes' => 30, 'description' => 'Work'])->assertNotFound();
    }

    public function test_document_api_upload_download_isolation(): void
    {
        Storage::fake('documents');
        (new LocalKeyProvider)->createVersion();
        $owner = $this->citizen();

        $document = $this->post('/api/v1/vault/documents', ['title' => 'ID', 'category' => 'identity', 'file' => UploadedFile::fake()->createWithContent('id.pdf', '%PDF-1.4 synthetic')], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->getJson('/api/v1/vault/documents')->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringContainsString('synthetic', $this->get("/api/v1/documents/{$document['id']}/download")->assertOk()->streamedContent());
        $this->assertArrayNotHasKey('storage_path', $this->getJson("/api/v1/documents/{$document['id']}")->assertOk()->json());

        $this->citizen();
        $this->getJson("/api/v1/documents/{$document['id']}")->assertNotFound();
        $this->get("/api/v1/documents/{$document['id']}/download", ['Accept' => 'application/json'])->assertNotFound();

        Sanctum::actingAs($owner, ['api:read', 'api:write']);
        $this->deleteJson("/api/v1/documents/{$document['id']}")->assertNoContent();
        $this->assertTrue(Document::withTrashed()->findOrFail($document['id'])->trashed());
    }

    public function test_forum_api_screens_and_hides_the_author(): void
    {
        $user = $this->citizen();
        $this->postJson('/api/v1/forum/questions', ['title' => 'A question about a deposit', 'body' => 'Please call me on 9876543210 about this.'])->assertStatus(422);
        $question = $this->postJson('/api/v1/forum/questions', ['title' => 'A question about a deposit', 'body' => 'My landlord kept my deposit after I moved out.'])->assertCreated()->json();
        $this->assertSame('published', $question['status']);

        $text = $this->getJson("/api/v1/forum/questions/{$question['id']}")->assertOk()->getContent();
        foreach ([$user->id, $user->email, 'author_user_id'] as $forbidden) {
            $this->assertStringNotContainsString((string) $forbidden, (string) $text);
        }
        $this->postJson("/api/v1/forum/questions/{$question['id']}/answers", ['body' => 'An answer from someone without the role.'])->assertForbidden();
    }

    public function test_privacy_api_records_the_mobile_channel(): void
    {
        $user = $this->citizen();
        $purpose = ConsentPurpose::query()->where('code', 'ml_training')->firstOrFail();

        $this->getJson('/api/v1/privacy/consents')->assertOk()->assertJsonFragment(['code' => 'ml_training']);
        $this->postJson("/api/v1/privacy/consents/{$purpose->id}", ['action' => 'grant'])->assertOk();
        $this->assertSame('mobile', DB::table('consent_records')->where('user_id', $user->id)->value('channel'));
        $this->assertTrue(app(ConsentLedger::class)->hasConsent($user, 'ml_training'));

        $mandatory = ConsentPurpose::query()->where('code', 'account')->firstOrFail();
        $this->postJson("/api/v1/privacy/consents/{$mandatory->id}", ['action' => 'withdraw'])->assertStatus(422);

        $this->postJson('/api/v1/privacy/requests', ['request_type' => 'erasure'])->assertCreated();
        $this->postJson('/api/v1/privacy/requests', ['request_type' => 'erasure'])->assertStatus(422);
        $this->getJson('/api/v1/privacy/requests')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_devices_are_owned_and_a_token_moves_between_users(): void
    {
        $first = $this->citizen();
        $device = $this->postJson('/api/v1/me/devices', ['platform' => 'android', 'push_token' => 'token-abc'])->assertCreated()->json();
        $this->postJson('/api/v1/me/devices', ['platform' => 'android', 'push_token' => 'token-abc', 'app_version' => '2'])->assertOk()->assertJsonPath('id', $device['id']);

        $second = $this->citizen();
        $this->deleteJson("/api/v1/me/devices/{$device['id']}")->assertNotFound();
        $this->postJson('/api/v1/me/devices', ['platform' => 'ios', 'push_token' => 'token-abc'])->assertCreated();
        $this->assertSame(0, DB::table('user_devices')->where('user_id', $first->id)->count());
        $this->assertSame(1, DB::table('user_devices')->where('user_id', $second->id)->count());
    }

    public function test_odr_api_journey_and_outsider_404(): void
    {
        DB::table('states')->insertOrIgnore(['code' => '33', 'name' => 'Tamil Nadu']);
        $claimant = $this->citizen();
        $this->postJson('/api/v1/odr/disputes', ['category' => 'service', 'title' => 'X', 'description' => 'Y'])->assertStatus(422);
        $dispute = $this->postJson('/api/v1/odr/disputes', ['category' => 'service', 'title' => 'Unpaid', 'description' => 'Facts here', 'accept_disclaimer' => true])->assertCreated()->json();
        $this->assertSame('awaiting_respondent', $dispute['status']);
        $this->getJson("/api/v1/odr/disputes/{$dispute['id']}")->assertOk()->assertJsonPath('settlement_label', 'Settlement agreement (unsigned)');
        $this->assertNotNull($claimant);

        $this->citizen();
        $this->getJson("/api/v1/odr/disputes/{$dispute['id']}")->assertNotFound();
        $this->postJson("/api/v1/odr/disputes/{$dispute['id']}/messages", ['body' => 'x'])->assertNotFound();
    }

    public function test_every_write_route_in_the_api_needs_the_write_ability(): void
    {
        $this->citizen(['api:read']);
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') || str_contains($uri, 'auth/') || $route->methods()[0] === 'GET') {
                continue;
            }
            $this->assertContains('api.ability', $route->gatherMiddleware(), "{$uri} is missing the ability check");
        }
    }
}
