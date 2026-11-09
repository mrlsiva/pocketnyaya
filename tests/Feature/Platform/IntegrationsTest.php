<?php

namespace Tests\Feature\Platform;

use App\Integrations\Contracts\AiDrafting;
use App\Integrations\Contracts\CourtDataProvider;
use App\Integrations\Contracts\ESignProvider;
use App\Integrations\Contracts\MessageSender;
use App\Integrations\Contracts\PaymentGateway;
use App\Integrations\Contracts\VideoProvider;
use App\Integrations\IntegrationNotConfigured;
use App\Integrations\PiiMasker;
use App\Models\Invoice;
use App\Models\OdrSession;
use App\Models\OdrSettlement;
use App\Models\TaxRule;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Practice\PracticeTestCase;

/** Phase 4: every outside service is switched off, makes no network call, and fails politely. */
class IntegrationsTest extends PracticeTestCase
{
    public function test_every_service_is_switched_off_and_never_calls_out(): void
    {
        Http::preventStrayRequests();
        config(['integrations.payments.driver' => 'some-unapproved-provider']);

        foreach ([
            fn () => app(PaymentGateway::class)->createPaymentLink(new Invoice),
            fn () => app(VideoProvider::class)->createMeeting(new OdrSession),
            fn () => app(ESignProvider::class)->requestSignature(new OdrSettlement, new User),
            fn () => app(CourtDataProvider::class)->caseStatus('TNCH010000012026'),
            fn () => app(AiDrafting::class)->draft('legal_notice', ['party' => 'X']),
            fn () => app(AiDrafting::class)->summarise('Text'),
            fn () => app(MessageSender::class)->push('token', 'Title', 'Body'),
            fn () => app(MessageSender::class)->sms('9876543210', 'Text'),
        ] as $call) {
            $this->assertThrows($call, IntegrationNotConfigured::class);
        }
    }

    public function test_masker_hides_identifiers_and_keeps_ordinary_text(): void
    {
        $result = app(PiiMasker::class)->mask('Call 9876543210 or +91 98765 43210, mail ravi@example.test, Aadhaar 2345 6789 0123, PAN ABCDE1234F, GSTIN 33ABCDE1234F1Z5, CNR TNCH010000012026, UPI ravi@okaxis, IFSC SBIN0001234, account 123456789012. The hearing is on 12 March 2026 under section 138.');

        foreach (['9876543210', '98765 43210', 'ravi@example.test', '2345 6789 0123', 'ABCDE1234F', '33ABCDE1234F1Z5', 'TNCH010000012026', 'ravi@okaxis', 'SBIN0001234', '123456789012'] as $secret) {
            $this->assertStringNotContainsString($secret, $result['text']);
        }
        $this->assertStringContainsString('12 March 2026 under section 138', $result['text']);
        $this->assertSame(1, $result['counts']['GSTIN']);
        $this->assertSame(1, $result['counts']['PAN'], 'Only the standalone PAN: the one inside the GSTIN is masked as part of the GSTIN.');
    }

    public function test_screens_show_a_plain_message_instead_of_an_error(): void
    {
        $rule = new TaxRule(['code' => 'T_'.Str::random(6), 'name' => 'Test', 'sac_code' => '9982', 'supplier_registration' => 'any', 'recipient_type' => 'any', 'recipient_turnover' => 'any', 'supply_type' => 'intra_state', 'charge_mechanism' => 'forward', 'cgst_rate' => '9', 'sgst_rate' => '9', 'igst_rate' => '0', 'invoice_type' => 'tax_invoice', 'priority' => 0, 'effective_from' => '2020-01-01']);
        $rule->forceFill(['approved_at' => now(), 'is_active' => true])->save();
        $firm = $this->firm();
        $service = app(InvoiceService::class);
        $invoice = $service->createDraft($this->client($firm), null, null, null);
        $service->addLine($invoice, ['line_type' => 'professional_fee', 'description' => 'Fee', 'quantity' => '1', 'unit_price' => '100']);
        $service->issue($invoice->fresh());

        $this->inTenant($this->member($firm, 'Finance Manager'), $firm);
        $this->from("/practice/invoices/{$invoice->id}")->post("/practice/invoices/{$invoice->id}/payment-link")
            ->assertRedirect("/practice/invoices/{$invoice->id}")->assertSessionHasErrors('integration');
    }

    public function test_integrations_page_is_for_platform_settings_staff_and_masks_by_post(): void
    {
        $admin = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $platform = (string) AccessContext::platformTenantId();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $platform, 'user_id' => $admin->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($admin, 'Super Admin', $platform);

        $this->actingAs($admin)->withSession(['mfa_passed_for' => $admin->id]);
        $this->get('/staff/admin/integrations')->assertOk()->assertSee('Switched off')->assertSee('Payment gateway');
        $this->post('/staff/admin/integrations/mask-preview', ['sample' => 'Phone 9876543210'])->assertOk()->assertSee('Phone [PHONE]')->assertSee('PHONE: 1');

        $customer = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($customer);
        $this->actingAs($customer)->get('/staff/admin/integrations')->assertForbidden();
    }
}
