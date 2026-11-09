<?php

namespace App\Integrations\Contracts;

use App\Models\Invoice;

/** Step 24. Creates a link the client can pay an invoice through. */
interface PaymentGateway
{
    /** @return array{url: string, reference: string} */
    public function createPaymentLink(Invoice $invoice): array;
}
