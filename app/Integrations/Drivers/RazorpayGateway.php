<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\PaymentGateway;
use App\Integrations\IntegrationFailed;
use App\Integrations\IntegrationNotConfigured;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Step 24 with Razorpay Payment Links. The link is for the balance due, in rupees. Only the
 * invoice number and amount go to Razorpay: no client name, email or phone, and Razorpay
 * sends no messages. The client is given the link by the firm. The ids in `notes` come
 * back on the signed webhook, which records the payment (RazorpayWebhookController).
 */
class RazorpayGateway implements PaymentGateway
{
    public const NAME = 'Payment gateway';

    public function createPaymentLink(Invoice $invoice): array
    {
        $config = (array) config('integrations.payments.razorpay');
        $keyId = (string) ($config['key_id'] ?? '');
        $secret = (string) ($config['key_secret'] ?? '');
        if ($keyId === '' || $secret === '' || (string) ($config['webhook_secret'] ?? '') === '') {
            throw new IntegrationNotConfigured(self::NAME);
        }
        $amount = Money::toPaise($invoice->balance_due);
        if ($amount <= 0) {
            throw new IntegrationFailed(self::NAME, 'this invoice has no balance due.');
        }

        try {
            $response = Http::withBasicAuth($keyId, $secret)->acceptJson()->timeout(20)
                ->post(rtrim((string) $config['base_url'], '/').'/payment_links', [
                    'amount' => $amount,
                    'currency' => 'INR',
                    'accept_partial' => false,
                    'description' => mb_substr('Invoice '.$invoice->invoice_number, 0, 2048),
                    'expire_by' => now()->addDays(max(1, (int) ($config['link_expiry_days'] ?? 15)))->getTimestamp(),
                    'notify' => ['sms' => false, 'email' => false],
                    'reminder_enable' => false,
                    'notes' => ['invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id, 'created_by' => (string) auth()->id()],
                ]);
        } catch (ConnectionException $e) {
            throw new IntegrationFailed(self::NAME, 'Razorpay could not be reached.', $e);
        }

        if (! $response->successful() || ! is_string($response->json('short_url')) || ! is_string($response->json('id'))) {
            throw new IntegrationFailed(self::NAME, 'Razorpay refused the request (status '.$response->status().').');
        }

        return ['url' => $response->json('short_url'), 'reference' => $response->json('id')];
    }
}
