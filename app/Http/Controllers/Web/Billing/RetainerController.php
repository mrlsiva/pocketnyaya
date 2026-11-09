<?php

namespace App\Http\Controllers\Web\Billing;

use App\Http\Controllers\Controller;
use App\Models\FeeArrangement;
use App\Models\Matter;
use App\Models\RetainerPlan;
use App\Models\RetainerWorkItem;
use App\Models\User;
use App\Support\AccessContext;
use App\Support\BillingAccess;
use App\Support\MatterAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Retainer plans and the work tracked under retainers (D8.9). Plans are the
 * practice's own private templates: no public tiers or packages (BCI Rule 36).
 */
class RetainerController extends Controller
{
    private const WORK_TYPES = ['brand_deal_review', 'ip_filing', 'non_compete_audit', 'dpdp_check', 'other'];

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $tenant = AccessContext::currentTenantId();
        $manageWork = AccessContext::tenantCan($user, 'billing.retainer_work.manage');

        $items = RetainerWorkItem::query()->with(['arrangement.client', 'matter'])->where('tenant_id', $tenant)
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'done')")->orderBy('due_at')->limit(200)->get()
            ->filter(fn (RetainerWorkItem $item) => $manageWork
                ? BillingAccess::canSeeMatterBilling($user, $item->arrangement?->matter)
                : $this->assignedToMe($user, $item));

        return view('billing.retainers', [
            'plans' => AccessContext::tenantCan($user, 'billing.fee_arrangements.view') || AccessContext::tenantCan($user, 'billing.retainer_plans.manage')
                ? RetainerPlan::query()->where('tenant_id', $tenant)->orderBy('name')->get() : collect(),
            'items' => $items,
            'arrangements' => $manageWork ? FeeArrangement::query()->with('client')->where('tenant_id', $tenant)
                ->whereIn('arrangement_type', ['monthly_retainer', 'creator_retainer'])->where('status', '<>', 'ended')->get()
                ->filter(fn (FeeArrangement $a) => BillingAccess::canSeeMatterBilling($user, $a->matter)) : collect(),
            'canManagePlans' => AccessContext::tenantCan($user, 'billing.retainer_plans.manage'),
            'canManageWork' => $manageWork,
            'workTypes' => self::WORK_TYPES,
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'billing.retainer_plans.manage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monthly_fee' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'scope_items' => ['nullable', 'string', 'max:2000'],
        ]);
        $scope = array_values(array_filter(array_map('trim', explode("\n", (string) ($data['scope_items'] ?? ''))), fn ($s) => $s !== ''));

        $plan = new RetainerPlan(['name' => $data['name'], 'description' => $data['description'] ?? null, 'monthly_fee' => $data['monthly_fee'], 'scope_items' => $scope]);
        $plan->forceFill(['tenant_id' => AccessContext::currentTenantId(), 'is_active' => true])->save();

        return back()->with('status', 'Retainer plan added.');
    }

    public function togglePlan(Request $request, RetainerPlan $plan): RedirectResponse
    {
        abort_unless($plan->tenant_id === AccessContext::currentTenantId(), 404);
        abort_unless(AccessContext::tenantCan($this->user($request), 'billing.retainer_plans.manage'), 403);
        $plan->forceFill(['is_active' => ! $plan->is_active])->save();

        return back()->with('status', $plan->is_active ? 'Plan reactivated.' : 'Plan retired. Existing arrangements keep it.');
    }

    public function storeWork(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::tenantCan($user, 'billing.retainer_work.manage'), 403);
        $data = $request->validate([
            'fee_arrangement_id' => ['required', 'uuid'],
            'matter_id' => ['nullable', 'uuid'],
            'work_type' => ['required', Rule::in(self::WORK_TYPES)],
            'title' => ['required', 'string', 'max:200'],
            'due_at' => ['nullable', 'date'],
        ]);

        $tenant = AccessContext::currentTenantId();
        $arrangement = FeeArrangement::query()->where(['id' => $data['fee_arrangement_id'], 'tenant_id' => $tenant])->first();
        abort_if($arrangement === null || ! $arrangement->isRetainer() || $arrangement->status === 'ended', 422, 'That retainer is not available.');
        abort_unless(BillingAccess::canSeeMatterBilling($user, $arrangement->matter), 404);
        if (! empty($data['matter_id'])) {
            abort_if(Matter::query()->where(['id' => $data['matter_id'], 'tenant_id' => $tenant, 'client_id' => $arrangement->client_id])->doesntExist(), 422, 'That matter is not for this client.');
        }

        $item = new RetainerWorkItem($data);
        $item->forceFill(['tenant_id' => $tenant, 'fee_arrangement_id' => $arrangement->id, 'status' => 'open'])->save();

        return back()->with('status', 'Work item added.');
    }

    public function updateWork(Request $request, RetainerWorkItem $item): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($item->tenant_id === AccessContext::currentTenantId(), 404);
        $manage = AccessContext::tenantCan($user, 'billing.retainer_work.manage') && BillingAccess::canSeeMatterBilling($user, $item->arrangement?->matter);
        abort_unless($manage || (AccessContext::tenantCan($user, 'billing.retainer_work.update_assigned') && $this->assignedToMe($user, $item)), 404);
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'done'])]]);

        $item->forceFill(['status' => $data['status'], 'completed_at' => $data['status'] === 'done' ? now() : null])->save();

        return back()->with('status', 'Work item updated.');
    }

    /** SA and JA see a work item only through a matter they're assigned to. */
    private function assignedToMe(User $user, RetainerWorkItem $item): bool
    {
        return $item->matter !== null && AccessContext::tenantCan($user, 'billing.retainer_work.update_assigned') && MatterAccess::assigned($user, $item->matter);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
