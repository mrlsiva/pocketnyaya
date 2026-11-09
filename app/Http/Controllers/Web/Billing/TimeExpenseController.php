<?php

namespace App\Http\Controllers\Web\Billing;

use App\Http\Controllers\Controller;
use App\Models\Matter;
use App\Models\MatterExpense;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\AccessContext;
use App\Support\BillingAccess;
use App\Support\MatterAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Implements D8.9 for time entries and expenses. */
class TimeExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);
        $tenant = AccessContext::currentTenantId();
        $me = MatterAccess::me($user);

        $seesAll = AccessContext::tenantCan($user, 'billing.time.view_all');
        $time = TimeEntry::query()->with(['matter', 'membership.user:id,name'])->where('tenant_id', $tenant)
            ->when(! $seesAll, fn ($q) => $q->where('membership_id', $me))->latest('work_date')->limit(100)->get()
            ->filter(fn (TimeEntry $t) => ! $seesAll || $t->membership_id === $me || BillingAccess::canSeeMatterBilling($user, $t->matter));

        $seesAllExpenses = AccessContext::tenantCan($user, 'billing.expenses.view_all');
        $expenses = MatterExpense::query()->with('matter')->where('tenant_id', $tenant)->latest('expense_date')->limit(100)->get()
            ->filter(fn (MatterExpense $e) => $e->matter !== null && (
                ($seesAllExpenses && BillingAccess::canSeeMatterBilling($user, $e->matter))
                || (AccessContext::tenantCan($user, 'billing.expenses.log') && MatterAccess::canView($user, $e->matter))
            ));

        return view('billing.time', [
            'time' => $time,
            'expenses' => $expenses,
            'matters' => MatterAccess::visibleMatters(Matter::query(), $user)->whereNotIn('status', ['closed', 'archived'])->orderByDesc('opened_at')->get(['id', 'matter_number', 'title']),
            'canLogTime' => AccessContext::tenantCan($user, 'billing.time.log_own'),
            'canLogExpense' => AccessContext::tenantCan($user, 'billing.expenses.log'),
            'me' => $me,
            'user' => $user,
        ]);
    }

    public function storeTime(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::tenantCan($user, 'billing.time.log_own'), 403);
        $data = $request->validate([
            'matter_id' => ['required', 'uuid'],
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'description' => ['required', 'string', 'max:500'],
            'is_billable' => ['sometimes', 'boolean'],
        ]);

        $matter = Matter::query()->find($data['matter_id']);
        abort_unless($matter !== null && MatterAccess::canView($user, $matter), 404);
        abort_if(in_array($matter->status, ['closed', 'archived'], true), 422, 'This matter is closed.');

        $entry = new TimeEntry($data);
        $entry->forceFill(['tenant_id' => $matter->tenant_id, 'membership_id' => MatterAccess::me($user), 'status' => 'draft'])->save();

        return back()->with('status', 'Time logged.');
    }

    public function destroyTime(Request $request, TimeEntry $entry): RedirectResponse
    {
        abort_unless($entry->membership_id === MatterAccess::me($this->user($request)), 404);
        abort_unless($entry->status === 'draft', 422);
        $entry->delete();

        return back()->with('status', 'Time entry removed.');
    }

    public function approveTime(Request $request, TimeEntry $entry): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($entry->tenant_id === AccessContext::currentTenantId(), 404);

        $all = AccessContext::tenantCan($user, 'billing.time.approve_all') && BillingAccess::canSeeMatterBilling($user, $entry->matter);
        $supervises = AccessContext::tenantCan($user, 'billing.time.approve_supervisees')
            && $entry->membership?->supervisor_membership_id === MatterAccess::me($user);
        abort_unless($all || $supervises, 403);
        abort_unless($entry->status === 'draft', 422);

        $entry->forceFill(['status' => 'approved'])->save();

        return back()->with('status', 'Time entry approved.');
    }

    public function storeExpense(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::tenantCan($user, 'billing.expenses.log'), 403);
        $data = $request->validate([
            'matter_id' => ['required', 'uuid'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'is_reimbursable' => ['sometimes', 'boolean'],
            'is_pure_agent' => ['sometimes', 'boolean'],
        ]);

        $matter = Matter::query()->find($data['matter_id']);
        $viaMatter = $matter !== null && MatterAccess::canView($user, $matter);
        $viaFinance = $matter !== null && $matter->tenant_id === AccessContext::currentTenantId()
            && AccessContext::tenantCan($user, 'billing.matter_summaries.view') && BillingAccess::canSeeMatterBilling($user, $matter);
        abort_unless($viaMatter || $viaFinance, 404);
        abort_if($matter->status === 'archived', 422);

        $expense = new MatterExpense($data);
        $expense->forceFill(['tenant_id' => $matter->tenant_id])->save();

        return back()->with('status', 'Expense logged.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
