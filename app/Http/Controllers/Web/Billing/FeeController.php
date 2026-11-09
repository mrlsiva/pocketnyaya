<?php

namespace App\Http\Controllers\Web\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FeeArrangement;
use App\Models\Matter;
use App\Models\User;
use App\Support\AccessContext;
use App\Support\BillingAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Fee arrangements. Private to the practice, and never shown publicly (BCI Rule 36). */
class FeeController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = AccessContext::currentTenantId();

        return view('billing.fees', [
            'arrangements' => FeeArrangement::query()->with(['client', 'matter'])->where('tenant_id', $tenant)->latest()->get()
                ->filter(fn (FeeArrangement $a) => BillingAccess::canSeeMatterBilling($user, $a->matter)),
            'clients' => Client::query()->where('tenant_id', $tenant)->orderBy('name')->get(['id', 'name']),
            'canManage' => AccessContext::tenantCan($user, 'billing.fee_arrangements.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(AccessContext::tenantCan($user, 'billing.fee_arrangements.manage'), 403);
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'matter_id' => ['nullable', 'uuid'],
            'arrangement_type' => ['required', Rule::in(['hourly', 'monthly_retainer', 'lump_sum', 'event_based', 'creator_retainer'])],
            'hourly_rate' => ['nullable', 'required_if:arrangement_type,hourly', 'numeric', 'min:0', 'decimal:0,2'],
            'retainer_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $tenant = AccessContext::currentTenantId();
        abort_if(Client::query()->where(['id' => $data['client_id'], 'tenant_id' => $tenant])->doesntExist(), 422);
        if (! empty($data['matter_id'])) {
            $matter = Matter::query()->where(['id' => $data['matter_id'], 'tenant_id' => $tenant, 'client_id' => $data['client_id']])->first();
            abort_if($matter === null, 422);
            abort_unless(BillingAccess::canSeeMatterBilling($user, $matter), 404);
        }

        $arrangement = new FeeArrangement($data);
        $arrangement->forceFill(['tenant_id' => $tenant, 'status' => 'active'])->save();

        return back()->with('status', 'Fee arrangement added.');
    }

    public function end(Request $request, FeeArrangement $arrangement): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($arrangement->tenant_id === AccessContext::currentTenantId() && BillingAccess::canSeeMatterBilling($user, $arrangement->matter), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.fee_arrangements.manage'), 403);
        $arrangement->forceFill(['status' => 'ended', 'ends_on' => now()->toDateString()])->save();

        return back()->with('status', 'Fee arrangement ended.');
    }
}
