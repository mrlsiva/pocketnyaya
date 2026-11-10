<?php

namespace Tests\Feature\Platform;

use App\Integrations\Contracts\AiDrafting;
use App\Integrations\Contracts\PaymentGateway;
use App\Integrations\Contracts\VideoProvider;
use App\Integrations\Drivers\AnthropicDrafting;
use App\Integrations\Drivers\ClaudeTextClient;
use App\Integrations\Drivers\JitsiVideo;
use App\Integrations\Drivers\RazorpayGateway;
use App\Integrations\IntegrationFailed;
use App\Integrations\IntegrationNotConfigured;
use App\Models\Invoice;
use App\Models\OdrSession;
use App\Models\Payment;
use App\Models\TaxRule;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Practice\PracticeTestCase;

/**
 * The owner's chosen providers (2026-11-10): Anthropic Claude, Razorpay and Jitsi. No test
 * makes a real network call: Razorpay is faked with Http::fake and Claude with a stand-in
 * client. All keys here are made up.
 */
class ProviderDriversTest extends PracticeTestCase
{
    private const WEBHOOK_SECRET = 'test-webhook-secret-not-real';

    private function razorpayOn(): void
    {
        config([
            'integrations.payments.driver' => 'razorpay',
            'integrations.payments.razorpay.key_id' => 'rzp_test_synthetic',
            'integrations.payments.razorpay.key_secret' => 'synthetic-secret',
            'integrations.payments.razorpay.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    /** @return array{0: string, 1: Invoice, 2: User} */
    private function issuedInvoice(string $price = '1000'): array
    {
        $rule = new TaxRule(['code' => 'T_'.Str::random(6), 'name' => 'Test', 'sac_code' => '9982', 'supplier_registration' => 'any', 'recipient_type' => 'any', 'recipient_turnover' => 'any', 'supply_type' => 'intra_state', 'charge_mechanism' => 'forward', 'cgst_rate' => '9', 'sgst_rate' => '9', 'igst_rate' => '0', 'invoice_type' => 'tax_invoice', 'priority' => 0, 'effective_from' => '2020-01-01']);
        $rule->forceFill(['approved_at' => now(), 'is_active' => true])->save();
        $firm = $this->firm();
        $service = app(InvoiceService::class);
        $invoice = $service->createDraft($this->client($firm), null, null, null);
        $service->addLine($invoice, ['line_type' => 'professional_fee', 'description' => 'Fee', 'quantity' => '1', 'unit_price' => $price]);

        return [$firm, $service->issue($invoice->fresh()), $this->member($firm, 'Finance Manager')];
    }

    /** @param  array<string, mixed>  $payload */
    private function webhook(array $payload, ?string $signature = null): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
    }

    /** @return array<string, mixed> */
    private function paidEvent(Invoice $invoice, User $by, string $paymentId, int $paise): array
    {
        return ['event' => 'payment_link.paid', 'payload' => [
            'payment_link' => ['entity' => ['id' => 'plink_synthetic', 'notes' => ['invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id, 'created_by' => $by->id]]],
            'payment' => ['entity' => ['id' => $paymentId, 'amount' => $paise, 'created_at' => now()->getTimestamp()]],
        ]];
    }

    public function test_drivers_are_chosen_only_by_their_exact_names(): void
    {
        config(['integrations.payments.driver' => 'razorpay', 'integrations.video.driver' => 'jitsi', 'integrations.ai.driver' => 'anthropic']);
        $this->assertInstanceOf(RazorpayGateway::class, app(PaymentGateway::class));
        $this->assertInstanceOf(JitsiVideo::class, app(VideoProvider::class));
        $this->assertInstanceOf(AnthropicDrafting::class, app(AiDrafting::class));
    }

    public function test_razorpay_creates_a_link_for_the_balance_with_no_client_details(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.razorpay.com/v1/payment_links' => Http::response(['id' => 'plink_synthetic', 'short_url' => 'https://rzp.io/i/synthetic'])]);
        $this->razorpayOn();
        [$firm, $invoice, $finance] = $this->issuedInvoice();

        $this->inTenant($finance, $firm);
        $this->from("/practice/invoices/{$invoice->id}")->post("/practice/invoices/{$invoice->id}/payment-link")
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Payment link: https://rzp.io/i/synthetic');

        Http::assertSent(function (HttpRequest $request) use ($invoice, $finance) {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_synthetic:synthetic-secret'))
                && $body['amount'] === 118000 && $body['currency'] === 'INR' && $body['accept_partial'] === false
                && $body['notes'] === ['invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id, 'created_by' => $finance->id]
                && ! array_key_exists('customer', $body) && $body['notify'] === ['sms' => false, 'email' => false];
        });
    }

    public function test_razorpay_needs_all_three_secrets_and_reports_refusals_plainly(): void
    {
        Http::preventStrayRequests();
        $this->razorpayOn();
        config(['integrations.payments.razorpay.webhook_secret' => null]);
        [, $invoice] = $this->issuedInvoice();
        $this->assertThrows(fn () => app(PaymentGateway::class)->createPaymentLink($invoice), IntegrationNotConfigured::class);

        $this->razorpayOn();
        Http::fake(['*' => Http::response(['error' => ['description' => 'Authentication failed']], 401)]);
        $this->assertThrows(fn () => app(PaymentGateway::class)->createPaymentLink($invoice), IntegrationFailed::class, 'status 401');
    }

    public function test_signed_webhook_records_the_payment_once(): void
    {
        $this->razorpayOn();
        [, $invoice, $finance] = $this->issuedInvoice();
        $event = $this->paidEvent($invoice, $finance, 'pay_synthetic_001', 118000);

        $this->webhook($event)->assertOk()->assertJson(['message' => 'recorded']);
        $this->webhook($event)->assertOk()->assertJson(['message' => 'duplicate']);

        $payment = Payment::query()->where('gateway_transaction_id', 'pay_synthetic_001')->sole();
        $this->assertSame('gateway', $payment->method);
        $this->assertSame($finance->id, $payment->received_by);
        $this->assertSame('paid', $invoice->fresh()?->status);
    }

    public function test_webhook_rejects_bad_signatures_and_is_hidden_when_switched_off(): void
    {
        $this->razorpayOn();
        [, $invoice, $finance] = $this->issuedInvoice();
        $event = $this->paidEvent($invoice, $finance, 'pay_synthetic_002', 118000);

        $this->webhook($event, str_repeat('0', 64))->assertStatus(400);
        $this->webhook($event, '')->assertStatus(400);
        $this->assertFalse(Payment::query()->where('gateway_transaction_id', 'pay_synthetic_002')->exists());

        config(['integrations.payments.driver' => 'none']);
        $this->webhook($event)->assertNotFound();
    }

    public function test_webhook_overpayment_is_left_for_a_person(): void
    {
        $this->razorpayOn();
        [, $invoice, $finance] = $this->issuedInvoice();

        $this->webhook($this->paidEvent($invoice, $finance, 'pay_synthetic_003', 999999))->assertOk()->assertJson(['message' => 'needs_review']);
        $this->assertFalse(Payment::query()->where('gateway_transaction_id', 'pay_synthetic_003')->exists());
    }

    public function test_webhook_records_the_payment_with_the_isolation_switch_on(): void
    {
        $this->razorpayOn();
        [, $invoice, $finance] = $this->issuedInvoice();
        $event = $this->paidEvent($invoice, $finance, 'pay_synthetic_iso', 118000);

        DB::statement('DELETE FROM pn_privileged_accounts');
        config(['pocketnyaya.db_isolation' => true]);
        DB::connection()->setTablePrefix('v_');
        try {
            $this->webhook($event)->assertOk()->assertJson(['message' => 'recorded']);
            $this->assertSame(0, DB::table('payments')->count(), 'The binding is cleared after the webhook.');
        } finally {
            DB::connection()->setTablePrefix('');
        }
        $this->assertTrue(Payment::query()->where('gateway_transaction_id', 'pay_synthetic_iso')->exists());
    }

    public function test_jitsi_makes_unguessable_https_links(): void
    {
        Http::preventStrayRequests();
        config(['integrations.video.driver' => 'jitsi', 'integrations.video.jitsi.base_url' => 'https://video.example.in/']);
        $first = app(VideoProvider::class)->createMeeting(new OdrSession);
        $second = app(VideoProvider::class)->createMeeting(new OdrSession);

        $this->assertMatchesRegularExpression('#^https://video\.example\.in/PocketNyaya-[A-Za-z0-9]{32}$#', $first);
        $this->assertNotSame($first, $second);
        $this->assertLessThanOrEqual(200, strlen($first), 'Fits the meeting_ref column.');

        config(['integrations.video.jitsi.base_url' => 'http://insecure.example']);
        $this->assertThrows(fn () => app(VideoProvider::class)->createMeeting(new OdrSession), IntegrationNotConfigured::class);
    }

    public function test_ai_masks_identifiers_before_anything_is_sent(): void
    {
        $fake = new class extends ClaudeTextClient
        {
            /** @var list<array{0: string, 1: string}> */
            public array $sent = [];

            public function send(string $system, string $prompt): string
            {
                $this->sent[] = [$system, $prompt];

                return 'Synthetic draft text';
            }
        };
        $this->app->instance(ClaudeTextClient::class, $fake);
        config(['integrations.ai.driver' => 'anthropic']);

        $draft = app(AiDrafting::class)->draft('Legal notice', ['Facts' => 'Tenant paid deposit, phone 9876543210, PAN ABCDE1234F, mail ravi@example.test.']);
        app(AiDrafting::class)->summarise('Aadhaar 2345 6789 0123 was shared with the landlord.');

        $this->assertSame('Synthetic draft text', $draft);
        $this->assertCount(2, $fake->sent);
        foreach ($fake->sent as [$system, $prompt]) {
            foreach (['9876543210', 'ABCDE1234F', 'ravi@example.test', '2345 6789 0123'] as $secret) {
                $this->assertStringNotContainsString($secret, $prompt);
            }
            $this->assertStringContainsString('never guess what they hide', $system);
        }
        $this->assertStringContainsString('[PHONE]', $fake->sent[0][1]);
    }

    public function test_claude_client_refuses_without_a_key_and_never_calls_out(): void
    {
        Http::preventStrayRequests();
        config(['integrations.ai.anthropic.api_key' => null]);
        $this->assertThrows(fn () => app(ClaudeTextClient::class)->send('system', 'prompt'), IntegrationNotConfigured::class);
    }

    public function test_drafting_screen_works_for_contributors_and_saves_nothing(): void
    {
        $fake = new class extends ClaudeTextClient
        {
            public function send(string $system, string $prompt): string
            {
                return 'Synthetic summary';
            }
        };
        $this->app->instance(ClaudeTextClient::class, $fake);
        $firm = $this->firm();
        $advocate = $this->member($firm, 'Managing Partner');
        $this->inTenant($advocate, $firm);

        $this->get('/practice/ai')->assertOk()->assertSee('The AI service is switched off');
        $this->from('/practice/ai')->post('/practice/ai/summary', ['text' => 'Synthetic text'])->assertRedirect('/practice/ai')->assertSessionHasErrors('integration');

        config(['integrations.ai.driver' => 'anthropic']);
        $this->post('/practice/ai/summary', ['text' => 'Synthetic text'])->assertOk()->assertSee('Synthetic summary')->assertSee('Check before use');
        $this->post('/practice/ai/draft', ['document_type' => 'Legal notice', 'facts' => 'Synthetic facts'])->assertOk()->assertSee('Synthetic summary');
    }
}
