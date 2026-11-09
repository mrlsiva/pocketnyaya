<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Matter;
use App\Models\User;

/**
 * RC-BV and redaction (D4.1, DA-29). A restriction only takes things away from
 * the Finance Manager, and it never hides the client, because GST needs it.
 */
class BillingAccess
{
    /** RC-BV(m): not restricted, or the user can see the matter. */
    public static function canSeeMatterBilling(User $user, ?Matter $matter): bool
    {
        return $matter === null || ! $matter->isBillingRestricted() || MatterAccess::canView($user, $matter);
    }

    public static function canViewInvoice(User $user, Invoice $invoice): bool
    {
        return $invoice->tenant_id === AccessContext::currentTenantId()
            && AccessContext::tenantCan($user, 'billing.invoices.view');
    }

    /** RS(m) ∧ ¬RC-MV(m): show totals, hide line descriptions and matter details. */
    public static function isRedacted(User $user, Invoice $invoice): bool
    {
        return ! self::canSeeMatterBilling($user, $invoice->matter);
    }

    public static function canEditInvoice(User $user, Invoice $invoice): bool
    {
        return self::canViewInvoice($user, $invoice) && self::canSeeMatterBilling($user, $invoice->matter);
    }
}
