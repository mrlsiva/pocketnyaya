<?php

namespace App\Http\Controllers\Web\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FeeArrangement;
use App\Models\FeeScheduleItem;
use App\Models\Matter;
use App\Models\RetainerPlan;
use App\Models\User;
use App\Support\AccessContext;
use App\Support\BillingAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'arrangements' => FeeArrangement::query()->with(['client', 'matter', 'plan', 'scheduleItems'])->where('tenant_id', $tenant)->latest()->get()
                ->filter(fn (FeeArrangement $a) => BillingAccess::canSeeMatterBilling($user, $a->matter)),
            'clients' => Client::query()->where('tenant_id', $tenant)->orderBy('name')->get(['id', 'name']),
            'plans' => RetainerPlan::query()->where(['tenant_id' => $tenant, 'is_active' => true])->orderBy('name')->get(['id', 'name', 'monthly_fee']),
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
            'retainer_plan_id' => ['nullable', 'uuid'],
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

        if (! empty($data['retainer_plan_id'])) {
            abort_unless(in_array($data['arrangement_type'], ['monthly_retainer', 'creator_retainer'], true), 422, 'Only a retainer can use a retainer plan.');
            abort_if(RetainerPlan::query()->where(['id' => $data['retainer_plan_id'], 'tenant_id' => $tenant, 'is_active' => true])->doesntExist(), 422, 'That plan is not available.');
        }

        $arrangement = new FeeArrangement($data);
        $arrangement->forceFill(['tenant_id' => $tenant, 'status' => 'active'])->save();

        return back()->with('status', 'Fee arrangement added.');
    }

    /** Fee schedule items follow their arrangement's permissions (D8.9). */
    public function storeItem(Request $request, FeeArrangement $arrangement): RedirectResponse
    {
        $this->authorizeManage($request, $arrangement);
        abort_if($arrangement->status === 'ended', 422, 'This arrangement has ended.');
        $data = $request->validate([
            'event_type' => ['required', Rule::in(['drafting', 'appearance', 'consultation', 'filing', 'conference', 'other'])],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
        ]);

        $item = new FeeScheduleItem($data);
        $item->forceFill(['tenant_id' => $arrangement->tenant_id, 'fee_arrangement_id' => $arrangement->id])->save();

        return back()->with('status', 'Scheduled fee added.');
    }

    public function destroyItem(Request $request, FeeScheduleItem $item): RedirectResponse
    {
        abort_unless($item->tenant_id === AccessContext::currentTenantId() && $item->arrangement !== null, 404);
        $this->authorizeManage($request, $item->arrangement);
        abort_if(DB::table('invoice_lines')->where('fee_schedule_item_id', $item->id)->exists(), 422, 'This fee is already on an invoice, so it is kept.');
        $item->delete();

        return back()->with('status', 'Scheduled fee removed.');
    }

    private function authorizeManage(Request $request, FeeArrangement $arrangement): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($arrangement->tenant_id === AccessContext::currentTenantId() && BillingAccess::canSeeMatterBilling($user, $arrangement->matter), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.fee_arrangements.manage'), 403);
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
