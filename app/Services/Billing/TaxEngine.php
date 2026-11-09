<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\TaxRule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Picks the tax rule for a supply. Rates live in approved rules and never in code.
 * A tenant override beats a platform rule, then higher priority wins. If nothing
 * approved matches, the invoice can't be issued: this never guesses a rate.
 */
class TaxEngine
{
    public function ruleFor(Tenant $supplier, Client $recipient, string $date): TaxRule
    {
        $registration = $supplier->gst_registration_type === 'unregistered' ? 'unregistered' : 'registered';
        $supply = $supplier->state_code === $recipient->state_code ? 'intra_state' : 'inter_state';
        $turnover = in_array($recipient->turnover_category, ['below_threshold', 'above_threshold'], true) ? $recipient->turnover_category : null;

        $rule = TaxRule::query()
            ->where('is_active', true)->whereNotNull('approved_at')
            ->where(fn (Builder $q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $supplier->id))
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->whereIn('supplier_registration', [$registration, 'any'])
            ->whereIn('recipient_type', [$recipient->client_type, 'any'])
            ->whereIn('supply_type', [$supply, 'any'])
            ->when($turnover !== null, fn (Builder $q) => $q->whereIn('recipient_turnover', [$turnover, 'any']), fn (Builder $q) => $q->where('recipient_turnover', 'any'))
            ->orderByRaw('tenant_id IS NULL')
            ->orderByDesc('priority')
            ->first();

        if ($rule === null) {
            throw ValidationException::withMessages(['tax' => 'No approved tax rule matches this invoice. A qualified accountant must approve one first.']);
        }

        return $rule;
    }
}
