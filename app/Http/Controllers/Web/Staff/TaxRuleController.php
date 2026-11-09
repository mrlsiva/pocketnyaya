<?php

namespace App\Http\Controllers\Web\Staff;

use App\Http\Controllers\Controller;
use App\Models\TaxRule;
use App\Models\User;
use App\Services\AuditLogWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** DA-10: the person who drafts a tax rule and the person who approves it must differ. */
class TaxRuleController extends Controller
{
    public function index(): View
    {
        return view('staff.tax-rules', ['rules' => TaxRule::query()->whereNull('tenant_id')->orderByDesc('effective_from')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'sac_code' => ['required', 'string', 'max:8'],
            'supplier_registration' => ['required', Rule::in(['registered', 'unregistered', 'any'])],
            'recipient_type' => ['required', Rule::in(['individual', 'business', 'government', 'any'])],
            'recipient_turnover' => ['required', Rule::in(['below_threshold', 'above_threshold', 'any'])],
            'supply_type' => ['required', Rule::in(['intra_state', 'inter_state', 'any'])],
            'charge_mechanism' => ['required', Rule::in(['forward', 'reverse', 'exempt', 'nil'])],
            'cgst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'sgst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'igst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'invoice_type' => ['required', Rule::in(['tax_invoice', 'bill_of_supply'])],
            'priority' => ['required', 'integer'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'legal_reference' => ['required', 'string', 'max:1000'],
        ]);

        $rule = new TaxRule($data);
        $rule->forceFill(['drafted_by' => $user->id, 'is_active' => false])->save();

        return back()->with('status', 'Draft saved. Another person must record its approval.');
    }

    public function approve(Request $request, TaxRule $rule): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($rule->tenant_id === null, 404);
        abort_if($rule->drafted_by === $user->id, 403, 'The person who drafted a rule cannot approve it.');
        abort_if($rule->approved_at !== null, 422);

        $rule->forceFill(['approved_by' => $user->id, 'approved_at' => now(), 'is_active' => true])->save();
        app(AuditLogWriter::class)->record('tax.rule_approved', 'tax_rule', $rule->id);

        return back()->with('status', 'Approval recorded. The rule is now active.');
    }
}
