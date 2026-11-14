<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Contracts\PaymentGateway;
use App\Models\Client;
use App\Models\FeeArrangement;
use App\Models\FeeScheduleItem;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Matter;
use App\Models\MatterExpense;
use App\Models\Payment;
use App\Models\RetainerPlan;
use App\Models\RetainerWorkItem;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Billing\FirmReports;
use App\Services\Billing\InvoiceService;
use App\Support\AccessContext;
use App\Support\BillingAccess;
use App\Support\MatterAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The billing actions the web screens have, for the app: the rest of the invoice life cycle,
 * fee arrangements, retainers, time approval, expenses and firm reports. Checks and
 * validation match Web\Billing\InvoiceController, FeeController, RetainerController and
 * TimeExpenseController. Money is returned as strings.
 */
class BillingOpsController extends Controller
{
    private const WORK_TYPES = ['brand_deal_review', 'ip_filing', 'non_compete_audit', 'dpdp_check', 'other'];

    // Invoices

    public function destroyDraft(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeDraftEdit($request, $invoice);
        abort_unless($invoice->status === 'draft', 422, 'Only a draft can be removed.');
        $invoices->deleteDraft($invoice);

        return response()->json(['message' => 'Draft removed.']);
    }

    public function removeLine(Request $request, Invoice $invoice, InvoiceLine $line, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeDraftEdit($request, $invoice);
        $invoices->removeLine($invoice, $line);

        return $this->invoice($invoice);
    }

    public function addScheduleItem(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeDraftEdit($request, $invoice);
        $data = $request->validate([
            'fee_schedule_item_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999', 'decimal:0,2'],
        ]);
        $item = FeeScheduleItem::query()->where(['id' => $data['fee_schedule_item_id'], 'tenant_id' => $invoice->tenant_id])->first();
        abort_if($item === null, 422, 'That fee is not available.');
        $invoices->addScheduleItem($invoice, $item, (string) $data['quantity']);

        return $this->invoice($invoice, 201);
    }

    public function addRetainer(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeDraftEdit($request, $invoice);
        $data = $request->validate(['fee_arrangement_id' => ['required', 'uuid'], 'period' => ['required', 'string', 'max:40']]);
        $arrangement = FeeArrangement::query()->where(['id' => $data['fee_arrangement_id'], 'tenant_id' => $invoice->tenant_id])->first();
        abort_if($arrangement === null, 422, 'That retainer is not available.');
        $invoices->addRetainer($invoice, $arrangement, $data['period']);

        return $this->invoice($invoice, 201);
    }

    public function cancel(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.invoices.cancel') && BillingAccess::canEditInvoice($user, $invoice), 403);
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);
        $invoices->cancel($invoice, $data['cancel_reason']);

        return $this->invoice($invoice);
    }

    /** Provisional treatment, pending a CA's review. Returns the new credit note. */
    public function creditNote(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.invoices.issue') && BillingAccess::canEditInvoice($user, $invoice), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $note = $invoices->issueCreditNote($invoice, $user, (string) $data['amount'], $data['reason']);

        return $this->invoice($note, 201);
    }

    /** 503 until a payment gateway is switched on. */
    public function paymentLink(Request $request, Invoice $invoice, PaymentGateway $gateway): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.payments.record'), 403);
        abort_unless($invoice->invoice_type !== 'credit_note' && in_array($invoice->status, ['issued', 'partially_paid'], true), 422, 'Only an issued invoice with a balance can have a payment link.');

        return response()->json($gateway->createPaymentLink($invoice), 201);
    }

    public function payments(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless(BillingAccess::canViewInvoice($this->user($request), $invoice), 404);

        return response()->json(['data' => DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.invoice_id', $invoice->id)->whereNull('payments.deleted_at')->orderBy('payments.payment_date')
            ->get(['payments.id', 'payments.payment_date', 'payments.method', 'payments.reference_number', 'payments.status', 'payment_allocations.amount', 'payment_allocations.tds_amount'])]);
    }

    public function reversePayment(Request $request, Payment $payment, InvoiceService $invoices): JsonResponse
    {
        abort_unless($payment->tenant_id === AccessContext::currentTenantId(), 404);
        abort_unless(AccessContext::tenantCan($this->user($request), 'billing.payments.reverse'), 403);
        $invoices->reversePayment($payment);

        return response()->json(['message' => 'Payment reversed.']);
    }

    /** PRD 2.4: totals, ageing, billable hours and fees by lead, for an Indian financial year. */
    public function reports(Request $request): JsonResponse
    {
        $tenant = (string) AccessContext::currentTenantId();
        $data = $request->validate(['fy' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $year = (int) ($data['fy'] ?? FirmReports::financialYear()[0]->year);
        [$from, $to] = FirmReports::financialYear(Carbon::create($year, 4, 1));
        $reports = app(FirmReports::class);

        $sums = DB::table('invoices')->where('tenant_id', $tenant)->whereNull('deleted_at')->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->selectRaw("COALESCE(SUM(CASE WHEN invoice_type = 'credit_note' THEN -subtotal ELSE subtotal END),0) fees,
                COALESCE(SUM(CASE WHEN invoice_type = 'credit_note' THEN -(cgst_amount+sgst_amount+igst_amount) ELSE cgst_amount+sgst_amount+igst_amount END),0) tax,
                COALESCE(SUM(CASE WHEN invoice_type = 'credit_note' THEN -total_amount ELSE amount_paid END),0) received,
                COALESCE(SUM(balance_due),0) outstanding")->first();

        return response()->json([
            'financial_year' => ['start' => $from->toDateString(), 'end' => $to->toDateString()],
            'totals' => $sums,
            'hours_by_member' => $reports->hoursByMember($tenant, $from, $to),
            'fees_by_lead' => $reports->revenueByLead($this->user($request), $tenant, $from, $to),
        ]);
    }

    // Fee arrangements

    public function fees(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => FeeArrangement::query()->with(['matter', 'scheduleItems'])->where('tenant_id', AccessContext::currentTenantId())->latest()->get()
            ->filter(fn (FeeArrangement $a) => BillingAccess::canSeeMatterBilling($user, $a->matter))
            ->map(fn (FeeArrangement $a) => $a->only(['id', 'client_id', 'matter_id', 'arrangement_type', 'hourly_rate', 'retainer_amount', 'retainer_plan_id', 'starts_on', 'ends_on', 'status', 'notes'])
                + ['schedule_items' => $a->scheduleItems->map(fn (FeeScheduleItem $i) => $i->only(['id', 'event_type', 'description', 'amount']))->values()])
            ->values()]);
    }

    public function storeFee(Request $request): JsonResponse
    {
        $user = $this->user($request);
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
        abort_if(Client::query()->where(['id' => $data['client_id'], 'tenant_id' => $tenant])->doesntExist(), 422, 'That client is not available.');
        if (! empty($data['matter_id'])) {
            $matter = Matter::query()->where(['id' => $data['matter_id'], 'tenant_id' => $tenant, 'client_id' => $data['client_id']])->first();
            abort_if($matter === null, 422, 'That matter is not available.');
            abort_unless(BillingAccess::canSeeMatterBilling($user, $matter), 404);
        }
        if (! empty($data['retainer_plan_id'])) {
            abort_unless(in_array($data['arrangement_type'], ['monthly_retainer', 'creator_retainer'], true), 422, 'Only a retainer can use a retainer plan.');
            abort_if(RetainerPlan::query()->where(['id' => $data['retainer_plan_id'], 'tenant_id' => $tenant, 'is_active' => true])->doesntExist(), 422, 'That plan is not available.');
        }
        $arrangement = new FeeArrangement($data);
        $arrangement->forceFill(['tenant_id' => $tenant, 'status' => 'active'])->save();

        return response()->json($arrangement->only(['id', 'client_id', 'matter_id', 'arrangement_type', 'status']), 201);
    }

    public function endFee(Request $request, FeeArrangement $arrangement): JsonResponse
    {
        $this->authorizeFee($request, $arrangement);
        $arrangement->forceFill(['status' => 'ended', 'ends_on' => now()->toDateString()])->save();

        return response()->json(['message' => 'Fee arrangement ended.']);
    }

    public function storeFeeItem(Request $request, FeeArrangement $arrangement): JsonResponse
    {
        $this->authorizeFee($request, $arrangement);
        abort_if($arrangement->status === 'ended', 422, 'This arrangement has ended.');
        $data = $request->validate([
            'event_type' => ['required', Rule::in(['drafting', 'appearance', 'consultation', 'filing', 'conference', 'other'])],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
        ]);
        $item = new FeeScheduleItem($data);
        $item->forceFill(['tenant_id' => $arrangement->tenant_id, 'fee_arrangement_id' => $arrangement->id])->save();

        return response()->json($item->only(['id', 'event_type', 'description', 'amount']), 201);
    }

    public function destroyFeeItem(Request $request, FeeScheduleItem $item): JsonResponse
    {
        abort_unless($item->tenant_id === AccessContext::currentTenantId() && $item->arrangement !== null, 404);
        $this->authorizeFee($request, $item->arrangement);
        abort_if(DB::table('invoice_lines')->where('fee_schedule_item_id', $item->id)->exists(), 422, 'This fee is already on an invoice, so it is kept.');
        $item->delete();

        return response()->json(['message' => 'Scheduled fee removed.']);
    }

    // Retainers

    public function retainers(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $tenant = AccessContext::currentTenantId();
        $manageWork = AccessContext::tenantCan($user, 'billing.retainer_work.manage');
        $items = RetainerWorkItem::query()->with(['arrangement', 'matter'])->where('tenant_id', $tenant)->orderBy('due_at')->limit(200)->get()
            ->filter(fn (RetainerWorkItem $item) => $manageWork ? BillingAccess::canSeeMatterBilling($user, $item->arrangement?->matter) : $this->assignedToMe($user, $item));
        $seesPlans = AccessContext::tenantCan($user, 'billing.fee_arrangements.view') || AccessContext::tenantCan($user, 'billing.retainer_plans.manage');

        return response()->json([
            'plans' => $seesPlans ? RetainerPlan::query()->where('tenant_id', $tenant)->orderBy('name')->get(['id', 'name', 'description', 'monthly_fee', 'scope_items', 'is_active']) : [],
            'work_items' => $items->map(fn (RetainerWorkItem $i) => $i->only(['id', 'fee_arrangement_id', 'matter_id', 'work_type', 'title', 'due_at', 'status', 'completed_at']))->values(),
            'work_types' => self::WORK_TYPES,
        ]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'billing.retainer_plans.manage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monthly_fee' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'scope_items' => ['nullable', 'array', 'max:50'],
            'scope_items.*' => ['string', 'max:200'],
        ]);
        $plan = new RetainerPlan(['name' => $data['name'], 'description' => $data['description'] ?? null, 'monthly_fee' => $data['monthly_fee'], 'scope_items' => array_values($data['scope_items'] ?? [])]);
        $plan->forceFill(['tenant_id' => AccessContext::currentTenantId(), 'is_active' => true])->save();

        return response()->json($plan->only(['id', 'name', 'monthly_fee', 'is_active']), 201);
    }

    public function togglePlan(Request $request, RetainerPlan $plan): JsonResponse
    {
        abort_unless($plan->tenant_id === AccessContext::currentTenantId(), 404);
        abort_unless(AccessContext::tenantCan($this->user($request), 'billing.retainer_plans.manage'), 403);
        $plan->forceFill(['is_active' => ! $plan->is_active])->save();

        return response()->json($plan->only(['id', 'name', 'is_active']));
    }

    public function storeWork(Request $request): JsonResponse
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

        return response()->json($item->only(['id', 'fee_arrangement_id', 'matter_id', 'work_type', 'title', 'due_at', 'status']), 201);
    }

    public function updateWork(Request $request, RetainerWorkItem $item): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($item->tenant_id === AccessContext::currentTenantId(), 404);
        $manage = AccessContext::tenantCan($user, 'billing.retainer_work.manage') && BillingAccess::canSeeMatterBilling($user, $item->arrangement?->matter);
        abort_unless($manage || (AccessContext::tenantCan($user, 'billing.retainer_work.update_assigned') && $this->assignedToMe($user, $item)), 404);
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'done'])]]);
        $item->forceFill(['status' => $data['status'], 'completed_at' => $data['status'] === 'done' ? now() : null])->save();

        return response()->json($item->only(['id', 'status', 'completed_at']));
    }

    // Time and expenses

    /** Everyone's time, for members who may see or approve it. Restricted matters stay hidden. */
    public function teamTime(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $me = MatterAccess::me($user);
        $all = AccessContext::tenantCan($user, 'billing.time.view_all');
        abort_unless($all || AccessContext::tenantCan($user, 'billing.time.approve_supervisees'), 403);
        $supervisees = DB::table('tenant_memberships')->where('supervisor_membership_id', $me)->pluck('id')->all();

        return response()->json(['data' => TimeEntry::query()->with('matter')->where('tenant_id', AccessContext::currentTenantId())
            ->when(! $all, fn ($q) => $q->whereIn('membership_id', $supervisees))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('work_date')->limit(200)->get()
            ->filter(fn (TimeEntry $t) => ! $all || BillingAccess::canSeeMatterBilling($user, $t->matter))
            ->map(fn (TimeEntry $t) => $t->only(['id', 'membership_id', 'matter_id', 'work_date', 'minutes', 'description', 'is_billable', 'status']))->values()]);
    }

    public function destroyTime(Request $request, TimeEntry $entry): JsonResponse
    {
        abort_unless($entry->membership_id === MatterAccess::me($this->user($request)), 404);
        abort_unless($entry->status === 'draft', 422, 'Only a draft entry can be removed.');
        $entry->delete();

        return response()->json(['message' => 'Time entry removed.']);
    }

    public function approveTime(Request $request, TimeEntry $entry): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($entry->tenant_id === AccessContext::currentTenantId(), 404);
        $all = AccessContext::tenantCan($user, 'billing.time.approve_all') && BillingAccess::canSeeMatterBilling($user, $entry->matter);
        $supervises = AccessContext::tenantCan($user, 'billing.time.approve_supervisees') && $entry->membership?->supervisor_membership_id === MatterAccess::me($user);
        abort_unless($all || $supervises, 403);
        abort_unless($entry->status === 'draft', 422, 'Only a draft entry can be approved.');
        $entry->forceFill(['status' => 'approved'])->save();

        return response()->json($entry->only(['id', 'status']));
    }

    public function expenses(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $seesAll = AccessContext::tenantCan($user, 'billing.expenses.view_all');

        return response()->json(['data' => MatterExpense::query()->with('matter')->where('tenant_id', AccessContext::currentTenantId())->latest('expense_date')->limit(100)->get()
            ->filter(fn (MatterExpense $e) => $e->matter !== null && (
                ($seesAll && BillingAccess::canSeeMatterBilling($user, $e->matter))
                || (AccessContext::tenantCan($user, 'billing.expenses.log') && MatterAccess::canView($user, $e->matter))
            ))
            ->map(fn (MatterExpense $e) => $e->only(['id', 'matter_id', 'expense_date', 'category', 'amount', 'is_reimbursable', 'is_pure_agent']))->values()]);
    }

    public function storeExpense(Request $request): JsonResponse
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
        abort_if($matter->status === 'archived', 422, 'This matter is archived.');
        $expense = new MatterExpense($data);
        $expense->forceFill(['tenant_id' => $matter->tenant_id])->save();

        return response()->json($expense->only(['id', 'matter_id', 'expense_date', 'category', 'amount', 'is_reimbursable', 'is_pure_agent']), 201);
    }

    private function invoice(Invoice $invoice, int $status = 200): JsonResponse
    {
        $fresh = $invoice->fresh() ?? $invoice;

        return response()->json($fresh->only(['id', 'invoice_number', 'invoice_type', 'original_invoice_id', 'status', 'client_id', 'issue_date', 'due_date', 'subtotal',
            'cgst_amount', 'sgst_amount', 'igst_amount', 'total_tax', 'total_amount', 'amount_paid', 'tds_deducted', 'balance_due']), $status);
    }

    private function authorizeDraftEdit(Request $request, Invoice $invoice): void
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.invoices.create_draft') && BillingAccess::canEditInvoice($user, $invoice), 403);
    }

    private function authorizeFee(Request $request, FeeArrangement $arrangement): void
    {
        $user = $this->user($request);
        abort_unless($arrangement->tenant_id === AccessContext::currentTenantId() && BillingAccess::canSeeMatterBilling($user, $arrangement->matter), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.fee_arrangements.manage'), 403);
    }

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
