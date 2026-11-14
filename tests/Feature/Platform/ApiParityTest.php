<?php

namespace Tests\Feature\Platform;

use App\Models\AdvocateProfile;
use App\Models\ConsultationRequest;
use App\Models\DataPrincipalRequest;
use App\Models\ForumQuestion;
use App\Models\Hearing;
use App\Models\Invoice;
use App\Models\KnowledgeItem;
use App\Models\MediatorProfile;
use App\Models\OdrDispute;
use App\Models\OdrMediatorAssignment;
use App\Models\OdrSession;
use App\Models\TaxRule;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\Documents\LocalKeyProvider;
use App\Services\OdrService;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use Database\Seeders\PrivacySeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Practice\PracticeTestCase;

/**
 * The API actions added on 2026-11-10 so the app can do what the web screens do. Each test
 * checks a role that may act and one that may not, with synthetic data only.
 */
class ApiParityTest extends PracticeTestCase
{
    private string $firm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, PrivacySeeder::class]);
        Storage::fake('documents');
        config(['pocketnyaya.document_key_dir' => storage_path('framework/testing/keys/'.uniqid())]);
        app(LocalKeyProvider::class)->createVersion();
        $this->firm = $this->firm();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) config('pocketnyaya.document_key_dir'));
        parent::tearDown();
    }

    private function as(User $user): array
    {
        Sanctum::actingAs($user, ['api:read', 'api:write']);

        return ['X-Tenant-ID' => $this->firm];
    }

    private function citizen(): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($user);
        Sanctum::actingAs($user, ['api:read', 'api:write']);

        return $user;
    }

    private function issuedInvoice(): Invoice
    {
        $rule = new TaxRule(['code' => 'T_'.Str::random(6), 'name' => 'Test', 'sac_code' => '9982', 'supplier_registration' => 'any', 'recipient_type' => 'any', 'recipient_turnover' => 'any', 'supply_type' => 'intra_state', 'charge_mechanism' => 'forward', 'cgst_rate' => '9', 'sgst_rate' => '9', 'igst_rate' => '0', 'invoice_type' => 'tax_invoice', 'priority' => 0, 'effective_from' => '2020-01-01']);
        $rule->forceFill(['approved_at' => now(), 'is_active' => true])->save();
        $service = app(InvoiceService::class);
        $invoice = $service->createDraft($this->client($this->firm), null, null, null);
        $service->addLine($invoice, ['line_type' => 'professional_fee', 'description' => 'Fee', 'quantity' => '1', 'unit_price' => '1000']);

        return $service->issue($invoice->fresh());
    }

    public function test_matter_life_cycle_allocation_parties_and_tasks(): void
    {
        $partner = $this->member($this->firm, 'Managing Partner');
        $junior = $this->member($this->firm, 'Junior Associate');
        $matter = $this->matter($this->firm);
        $this->assign($matter, $junior, 'junior');

        $h = $this->as($partner);
        $this->getJson("/api/v1/matters/{$matter->id}/allocation", $h)->assertOk()->assertJsonStructure(['data' => [['membership_id', 'name', 'availability', 'active_matters', 'at_capacity']]]);
        $this->getJson("/api/v1/matters/{$matter->id}/assignments", $h)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/matters/{$matter->id}/parties", ['party_role' => 'respondent', 'name' => 'Synthetic Opponent'], $h)->assertCreated();
        $this->postJson("/api/v1/matters/{$matter->id}/tasks", ['title' => 'Draft reply', 'priority' => 'high', 'assigned_to_membership_id' => $this->membership($this->firm, $junior)], $h)->assertCreated();
        $this->postJson("/api/v1/matters/{$matter->id}/close", [], $h)->assertOk()->assertJson(['status' => 'closed']);
        $this->postJson("/api/v1/matters/{$matter->id}/reopen", [], $h)->assertOk()->assertJson(['status' => 'open']);

        $h = $this->as($junior);
        $this->getJson("/api/v1/matters/{$matter->id}/allocation", $h)->assertForbidden();
        $this->postJson("/api/v1/matters/{$matter->id}/close", [], $h)->assertForbidden();
        $this->deleteJson("/api/v1/matters/{$matter->id}", [], $h)->assertForbidden();
        $this->getJson("/api/v1/matters/{$matter->id}/parties", $h)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_calendar_diary_entries_and_hearing_removal(): void
    {
        $partner = $this->member($this->firm, 'Managing Partner');
        $matter = $this->matter($this->firm);
        $hearing = new Hearing(['court_id' => $this->court(), 'hearing_date' => now()->addDay()->toDateString(), 'purpose' => 'Arguments']);
        $hearing->forceFill(['tenant_id' => $this->firm, 'matter_id' => $matter->id, 'status' => 'scheduled'])->save();

        $h = $this->as($partner);
        $this->getJson('/api/v1/calendar', $h)->assertOk()->assertJsonPath('data.0.id', $hearing->id);
        $entry = $this->postJson('/api/v1/diary/entries', ['entry_type' => 'meeting', 'title' => 'Client meeting', 'starts_at' => now()->addHour()->toDateTimeString()], $h)->assertCreated()->json('id');
        $this->getJson('/api/v1/diary/entries', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/diary/entries/{$entry}", [], $h)->assertOk();
        $this->deleteJson("/api/v1/hearings/{$hearing->id}", [], $h)->assertOk();
        $this->assertSoftDeleted('hearings', ['id' => $hearing->id]);

        $this->getJson('/api/v1/calendar', $this->as($this->member($this->firm, 'Finance Manager')))->assertForbidden();
    }

    public function test_invoice_credit_note_payments_and_cancellation(): void
    {
        $invoice = $this->issuedInvoice();
        $finance = $this->member($this->firm, 'Finance Manager');
        $h = $this->as($finance);

        $note = $this->postJson("/api/v1/invoices/{$invoice->id}/credit-notes", ['amount' => '118.00', 'reason' => 'Fee reduced'], $h)
            ->assertCreated()->assertJson(['invoice_type' => 'credit_note', 'original_invoice_id' => $invoice->id])->json();
        $this->assertStringStartsWith('CN', (string) $note['invoice_number']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => '100.00', 'payment_date' => now()->toDateString(), 'method' => 'upi'], $h)->assertCreated();
        $payments = $this->getJson("/api/v1/invoices/{$invoice->id}/payments", $h)->assertOk()->assertJsonCount(2, 'data')->json('data');
        $upi = collect($payments)->firstWhere('method', 'upi');
        $this->postJson("/api/v1/payments/{$upi['id']}/reverse", [], $h)->assertOk();

        $this->postJson("/api/v1/invoices/{$invoice->id}/payment-link", [], $h)->assertStatus(503)->assertJson(['integration' => 'Payment gateway']);
        $this->getJson('/api/v1/reports', $h)->assertOk()->assertJsonStructure(['financial_year', 'totals', 'hours_by_member', 'fees_by_lead']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/credit-notes", ['amount' => '1', 'reason' => 'x'], $this->as($this->member($this->firm, 'Senior Associate')))->assertForbidden();
    }

    public function test_drafts_can_be_removed_and_lines_edited(): void
    {
        $partner = $this->member($this->firm, 'Managing Partner');
        $h = $this->as($partner);
        $client = $this->client($this->firm);
        $draft = $this->postJson('/api/v1/invoices', ['client_id' => $client->id], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$draft}/lines", ['line_type' => 'professional_fee', 'description' => 'Advice', 'quantity' => '1', 'unit_price' => '500'], $h)->assertCreated();
        $line = (string) DB::table('invoice_lines')->where('invoice_id', $draft)->value('id');
        $this->deleteJson("/api/v1/invoices/{$draft}/lines/{$line}", [], $h)->assertOk();
        $this->deleteJson("/api/v1/invoices/{$draft}", [], $h)->assertOk();
        $this->assertSoftDeleted('invoices', ['id' => $draft]);
    }

    public function test_fee_arrangements_retainers_time_and_expenses(): void
    {
        $finance = $this->member($this->firm, 'Finance Manager');
        $senior = $this->member($this->firm, 'Senior Associate');
        $client = $this->client($this->firm);
        $matter = $this->matter($this->firm, $client);
        $this->assign($matter, $senior, 'lead');

        $h = $this->as($finance);
        $plan = $this->postJson('/api/v1/retainers/plans', ['name' => 'Creator plan', 'monthly_fee' => '25000', 'scope_items' => ['Two contract reviews']], $h)->assertCreated()->json('id');
        $fee = $this->postJson('/api/v1/fees', ['client_id' => $client->id, 'arrangement_type' => 'monthly_retainer', 'retainer_amount' => '25000', 'retainer_plan_id' => $plan, 'starts_on' => now()->toDateString()], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/fees/{$fee}/items", ['event_type' => 'appearance', 'description' => 'Each appearance', 'amount' => '5000'], $h)->assertCreated();
        $this->postJson('/api/v1/retainers/work', ['fee_arrangement_id' => $fee, 'matter_id' => $matter->id, 'work_type' => 'brand_deal_review', 'title' => 'Review sponsor contract'], $h)->assertCreated();
        $this->getJson('/api/v1/fees', $h)->assertOk()->assertJsonPath('data.0.schedule_items.0.description', 'Each appearance');
        $this->getJson('/api/v1/retainers', $h)->assertOk()->assertJsonCount(1, 'work_items');
        $this->postJson('/api/v1/expenses', ['matter_id' => $matter->id, 'expense_date' => now()->toDateString(), 'category' => 'court_fee', 'amount' => '250'], $h)->assertCreated();

        $h = $this->as($senior);
        $this->getJson('/api/v1/fees', $h)->assertForbidden();
        $entry = $this->postJson('/api/v1/time-entries', ['matter_id' => $matter->id, 'work_date' => now()->toDateString(), 'minutes' => 90, 'description' => 'Drafting'], $h)->assertCreated()->json('id');
        $this->getJson('/api/v1/expenses', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/time-entries/{$entry}/approve", [], $h)->assertForbidden();

        $h = $this->as($finance);
        $this->getJson('/api/v1/time-entries/team', $h)->assertOk()->assertJsonPath('data.0.id', $entry);
        $this->postJson("/api/v1/time-entries/{$entry}/approve", [], $h)->assertOk()->assertJson(['status' => 'approved']);
        $this->assertSame('approved', TimeEntry::query()->find($entry)?->status);
    }

    public function test_consultation_inbox_for_the_addressed_advocate(): void
    {
        $advocate = $this->member($this->firm, 'Senior Associate');
        $other = $this->member($this->firm, 'Junior Associate');
        $customer = User::factory()->create();
        $consultation = new ConsultationRequest(['mode' => 'video', 'subject' => 'Tenancy deposit', 'description' => 'Synthetic description']);
        $consultation->forceFill(['customer_user_id' => $customer->id, 'advocate_profile_id' => AdvocateProfile::factory()->create(['user_id' => $advocate->id])->id, 'tenant_id' => $this->firm, 'advocate_membership_id' => $this->membership($this->firm, $advocate), 'status' => 'requested'])->save();

        $h = $this->as($advocate);
        $this->getJson('/api/v1/practice/consultations', $h)->assertOk()->assertJsonPath('data.0.id', $consultation->id);
        $this->getJson("/api/v1/practice/consultations/{$consultation->id}", $h)->assertOk()->assertJson(['can_respond' => true, 'can_keep_notes' => true]);
        $this->putJson("/api/v1/practice/consultations/{$consultation->id}/notes", ['lawyer_private_notes' => 'Ask for the lease'], $h)->assertOk();
        $this->postJson("/api/v1/practice/consultations/{$consultation->id}/messages", ['body' => 'Please share the lease.'], $h)->assertCreated();

        $this->getJson("/api/v1/practice/consultations/{$consultation->id}", $this->as($other))->assertNotFound();
    }

    public function test_documents_folders_versions_and_the_privileged_rule(): void
    {
        $partner = $this->member($this->firm, 'Managing Partner');
        $clerk = $this->member($this->firm, 'Clerk / Office Staff');
        $matter = $this->matter($this->firm);
        $this->assign($matter, $clerk, 'clerk');
        $file = fn () => UploadedFile::fake()->createWithContent('f.pdf', '%PDF-1.4 synthetic');

        $h = $this->as($clerk);
        $this->postJson("/api/v1/matters/{$matter->id}/documents", ['title' => 'Secret', 'category' => 'other', 'confidentiality' => 'privileged', 'file' => $file()], $h)
            ->assertForbidden();
        $this->postJson("/api/v1/matters/{$matter->id}/documents", ['title' => 'Filing copy', 'category' => 'other', 'file' => $file()], $h)->assertCreated();

        $h = $this->as($partner);
        $folder = $this->postJson('/api/v1/practice/folders', ['name' => 'Precedents'], $h)->assertCreated()->json('id');
        $doc = $this->postJson('/api/v1/practice/documents', ['title' => 'Firm policy', 'category' => 'other', 'folder_id' => $folder, 'file' => $file()], $h)
            ->assertCreated()->assertJson(['folder_id' => $folder])->json('id');
        $this->postJson("/api/v1/practice/documents/{$doc}/versions", ['file' => $file(), 'change_note' => 'Updated'], $h)->assertCreated()->assertJson(['latest_version' => 2]);
        $this->getJson("/api/v1/documents/{$doc}", $h)->assertOk()->assertJsonCount(2, 'versions');
    }

    public function test_knowledge_settings_capacity_audit_and_ai(): void
    {
        $partner = $this->member($this->firm, 'Managing Partner');
        $junior = $this->member($this->firm, 'Junior Associate');

        $h = $this->as($junior);
        $item = $this->postJson('/api/v1/knowledge', ['item_type' => 'template', 'title' => 'Legal notice template', 'tags' => ['notice', 'recovery']], $h)->assertCreated()->assertJson(['status' => 'draft'])->json('id');
        $this->postJson("/api/v1/knowledge/{$item}/approve", [], $h)->assertForbidden();
        $this->putJson('/api/v1/practice/settings', ['name' => 'Renamed', 'gst_registration_type' => 'unregistered', 'state_code' => '33'], $h)->assertForbidden();
        $this->putJson('/api/v1/practice/settings/practice-areas', ['practice_area_ids' => [(int) DB::table('practice_areas')->value('id')]], $h)->assertOk();
        $this->postJson('/api/v1/ai/summary', ['text' => 'Synthetic text'], $h)->assertStatus(503);

        $h = $this->as($partner);
        $this->postJson("/api/v1/knowledge/{$item}/approve", [], $h)->assertOk()->assertJson(['status' => 'approved']);
        $this->assertSame('approved', KnowledgeItem::query()->find($item)?->status);
        $this->putJson('/api/v1/practice/settings', ['name' => 'Renamed Firm', 'gst_registration_type' => 'unregistered', 'state_code' => '33'], $h)->assertOk()->assertJson(['name' => 'Renamed Firm']);
        $this->getJson('/api/v1/practice/settings', $h)->assertOk()->assertJsonPath('practice.name', 'Renamed Firm')->assertJsonPath('can.update', true);
        $this->putJson("/api/v1/team/{$junior->id}/capacity", ['max_active_matters' => 5], $h)->assertOk();
        $this->assertSame(5, (int) DB::table('tenant_memberships')->where(['tenant_id' => $this->firm, 'user_id' => $junior->id])->value('max_active_matters'));
        $this->getJson('/api/v1/practice/audit', $h)->assertOk()->assertJsonStructure(['from', 'to', 'logs' => ['data']]);
    }

    public function test_citizen_opens_a_practice_and_uses_the_forum_and_privacy_tools(): void
    {
        $user = $this->citizen();
        $tenant = $this->postJson('/api/v1/practices', [
            'type' => 'solo', 'name' => 'Synthetic Chambers', 'state_code' => '33',
            'bar_council_id' => (int) DB::table('bar_councils')->value('id'), 'enrollment_number' => 'SYN/'.random_int(1000, 9999).'/2020',
            'enrollment_date' => '2020-01-01', 'city_id' => (int) DB::table('cities')->value('id'),
        ])->assertCreated()->json('id');
        $this->getJson('/api/v1/me/workspaces')->assertOk()->assertSee($tenant);

        $question = new ForumQuestion(['title' => 'A synthetic forum question', 'body' => 'My landlord has not returned my deposit.']);
        $question->forceFill(['author_user_id' => $user->id, 'public_alias' => 'Synthetic asker', 'status' => 'published', 'published_at' => now()])->save();
        $this->getJson('/api/v1/forum/mine')->assertOk()->assertJsonPath('data.0.id', $question->id);
        $this->postJson('/api/v1/forum/comments', ['type' => 'forum_question', 'id' => $question->id, 'body' => 'Same problem here.'])->assertCreated();
        $this->getJson("/api/v1/forum/questions/{$question->id}")->assertOk()->assertJsonStructure(['comments', 'answers']);

        $request = new DataPrincipalRequest(['request_type' => 'access']);
        $request->forceFill(['user_id' => $user->id, 'status' => 'submitted', 'due_at' => now()->addDays(30)])->save();
        $this->postJson("/api/v1/privacy/requests/{$request->id}/withdraw")->assertOk()->assertJson(['status' => 'withdrawn']);
        $this->getJson('/api/v1/privacy/export')->assertOk()->assertJsonStructure(['account', 'consents', 'vault_documents', 'requests']);
        $this->getJson('/api/v1/practice-exports')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/emergency/assigned')->assertForbidden();

        $this->citizen();
        $this->postJson("/api/v1/privacy/requests/{$request->id}/withdraw")->assertNotFound();
    }

    public function test_mediator_creates_a_meeting_link_and_the_claimant_can_withdraw(): void
    {
        $claimant = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($claimant);
        $odr = app(OdrService::class);
        $dispute = $odr->submit($odr->createDraft($claimant, ['category' => 'service', 'title' => 'Unpaid invoice', 'description' => 'Synthetic facts.']), []);

        $mediator = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($mediator);
        app(RoleAssignmentService::class)->assign($mediator, 'Mediator', (string) AccessContext::platformTenantId());
        $profile = MediatorProfile::factory()->create(['user_id' => $mediator->id]);
        $profile->forceFill(['verification_status' => 'verified', 'is_available' => true])->save();
        // Skips the respondent joining: the dispute moves straight to negotiation for this test.
        $dispute->forceFill(['status' => 'negotiation'])->save();
        $odr->proposeMediator($dispute, $profile, User::factory()->create());
        $odr->respondToAssignment(OdrMediatorAssignment::query()->where('dispute_id', $dispute->id)->firstOrFail(), true, 'None');
        $session = $odr->scheduleSession($dispute->fresh() ?? $dispute, ['scheduled_at' => now()->addDay()->toDateTimeString(), 'duration_minutes' => 60, 'mode' => 'video']);

        Sanctum::actingAs($mediator, ['api:read', 'api:write']);
        $url = "/api/v1/odr/disputes/{$dispute->id}/sessions/{$session->id}/meeting";
        $this->postJson($url)->assertStatus(503);
        config(['integrations.video.driver' => 'jitsi']);
        $link = $this->postJson($url)->assertCreated()->json('meeting_ref');
        $this->assertStringStartsWith('https://meet.jit.si/PocketNyaya-', $link);

        Sanctum::actingAs($claimant, ['api:read', 'api:write']);
        $this->getJson("/api/v1/odr/disputes/{$dispute->id}")->assertOk()->assertJsonPath('sessions.0.meeting_ref', $link);
        $this->postJson($url)->assertNotFound();
        $this->postJson("/api/v1/odr/disputes/{$dispute->id}/withdraw")->assertOk();
        $this->assertSame('withdrawn', OdrDispute::query()->find($dispute->id)?->status);
        $this->assertSame($link, OdrSession::query()->find($session->id)?->meeting_ref);
    }
}
