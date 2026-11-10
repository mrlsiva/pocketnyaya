<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Support\Isolation;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Razorpay tells us a payment link was paid. Nothing is trusted until the signature over
 * the raw body matches the webhook secret. The invoice, practice and the member who made
 * the link come from the notes we set when creating it. Each Razorpay payment id is
 * recorded once, so a repeated delivery changes nothing.
 */
class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, InvoiceService $invoices): JsonResponse
    {
        $secret = (string) config('integrations.payments.razorpay.webhook_secret');
        abort_if(config('integrations.payments.driver') !== 'razorpay' || $secret === '', 404);

        $signature = (string) $request->header('X-Razorpay-Signature', '');
        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload) || ($payload['event'] ?? null) !== 'payment_link.paid') {
            return response()->json(['message' => 'Ignored.']);
        }
        $notes = (array) data_get($payload, 'payload.payment_link.entity.notes', []);
        $paymentId = (string) data_get($payload, 'payload.payment.entity.id', '');
        $paise = (int) data_get($payload, 'payload.payment.entity.amount', 0);
        $invoiceId = (string) ($notes['invoice_id'] ?? '');
        $tenantId = (string) ($notes['tenant_id'] ?? '');
        $userId = (string) ($notes['created_by'] ?? '');
        if ($paymentId === '' || $paise <= 0 || $invoiceId === '' || $tenantId === '' || $userId === '') {
            Log::warning('Razorpay webhook without the expected fields.', ['payment' => $paymentId]);

            return response()->json(['message' => 'Ignored.']);
        }

        if (Isolation::enabled()) {
            Isolation::bindTenant($userId, $tenantId);
        }
        try {
            $result = DB::transaction(function () use ($invoices, $invoiceId, $tenantId, $userId, $paymentId, $paise, $payload) {
                $invoice = Invoice::query()->where(['id' => $invoiceId, 'tenant_id' => $tenantId])->lockForUpdate()->first();
                $user = User::query()->find($userId);
                if ($invoice === null || $user === null) {
                    return 'missing';
                }
                if (Payment::query()->where(['tenant_id' => $tenantId, 'gateway_transaction_id' => $paymentId])->exists()) {
                    return 'duplicate';
                }
                $paidAt = (int) data_get($payload, 'payload.payment.entity.created_at', 0);
                $invoices->recordPayment($invoice, $user, [
                    'amount' => Money::fromPaise($paise),
                    'payment_date' => ($paidAt > 0 ? Carbon::createFromTimestamp($paidAt) : now())->timezone('Asia/Kolkata')->toDateString(),
                    'method' => 'gateway',
                    'reference_number' => mb_substr($paymentId, 0, 80),
                    'gateway_transaction_id' => mb_substr($paymentId, 0, 100),
                ]);

                return 'recorded';
            });
        } catch (ValidationException $e) {
            // For example, more than the balance due after a credit note. A person must look at it.
            Log::warning('Razorpay payment not recorded automatically: '.$e->getMessage(), ['payment' => $paymentId, 'invoice' => $invoiceId]);
            $result = 'needs_review';
        } finally {
            if (Isolation::enabled()) {
                Isolation::clear();
            }
        }

        if ($result !== 'recorded') {
            Log::info('Razorpay webhook: '.$result, ['payment' => $paymentId, 'invoice' => $invoiceId]);
        }

        // Always 200 once the signature is valid, so Razorpay does not retry what we have already seen.
        return response()->json(['message' => $result]);
    }
}
