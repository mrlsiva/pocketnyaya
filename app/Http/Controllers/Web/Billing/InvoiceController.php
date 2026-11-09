<?php

namespace App\Http\Controllers\Web\Billing;

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
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AuditLogWriter;
use App\Services\Billing\InvoiceService;
use App\Support\AccessContext;
use App\Support\BillingAccess;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = AccessContext::currentTenantId();
        $status = (string) $request->query('status');

        return view('billing.invoices', [
            'invoices' => Invoice::query()->with('client')->where('tenant_id', $tenant)
                ->when($status !== '', fn ($q) => $q->where('status', $status))->latest()->paginate(25)->withQueryString(),
            'status' => $status,
            'clients' => Client::query()->where('tenant_id', $tenant)->orderBy('name')->get(['id', 'name']),
            'canCreate' => AccessContext::tenantCan($this->user($request), 'billing.invoices.create_draft'),
        ]);
    }

    public function store(Request $request, InvoiceService $invoices): RedirectResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'billing.invoices.create_draft'), 403);
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'matter_id' => ['nullable', 'uuid'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $tenant = AccessContext::currentTenantId();
        $client = Client::query()->where(['id' => $data['client_id'], 'tenant_id' => $tenant])->first();
        abort_if($client === null, 422, 'That client is not available.');
        $matter = empty($data['matter_id']) ? null : Matter::query()->where(['id' => $data['matter_id'], 'tenant_id' => $tenant, 'client_id' => $client->id])->first();
        abort_if(! empty($data['matter_id']) && $matter === null, 422, 'That matter is not available.');
        abort_unless(BillingAccess::canSeeMatterBilling($this->user($request), $matter), 404);

        $invoice = $invoices->createDraft($client, $matter, $data['due_date'] ?? null, $data['notes'] ?? null);

        return redirect()->route('practice.invoices.show', $invoice)->with('status', 'Draft invoice created.');
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        $redacted = BillingAccess::isRedacted($user, $invoice);

        $canEdit = BillingAccess::canEditInvoice($user, $invoice) && $invoice->status === 'draft' && AccessContext::tenantCan($user, 'billing.invoices.create_draft');

        return view('billing.invoice', $this->billableSources($invoice, $canEdit) + [
            'invoice' => $invoice->load(['client', 'matter', 'lines']),
            'redacted' => $redacted,
            'canEdit' => $canEdit,
            'canIssue' => BillingAccess::canEditInvoice($user, $invoice) && $invoice->status === 'draft' && AccessContext::tenantCan($user, 'billing.invoices.issue'),
            'canCancel' => $invoice->invoice_type !== 'credit_note' && BillingAccess::canEditInvoice($user, $invoice) && in_array($invoice->status, ['issued', 'partially_paid'], true) && AccessContext::tenantCan($user, 'billing.invoices.cancel'),
            'canPay' => $invoice->invoice_type !== 'credit_note' && in_array($invoice->status, ['issued', 'partially_paid'], true) && AccessContext::tenantCan($user, 'billing.payments.record'),
            'canReverse' => AccessContext::tenantCan($user, 'billing.payments.reverse'),
            'canCredit' => $invoice->invoice_type !== 'credit_note' && BillingAccess::canEditInvoice($user, $invoice)
                && in_array($invoice->status, ['issued', 'partially_paid'], true) && AccessContext::tenantCan($user, 'billing.invoices.issue'),
            'original' => $invoice->original_invoice_id === null ? null : Invoice::query()->find($invoice->original_invoice_id, ['id', 'invoice_number']),
            'creditNotes' => Invoice::query()->where(['original_invoice_id' => $invoice->id, 'invoice_type' => 'credit_note'])->get(['id', 'invoice_number', 'total_amount']),
            'payments' => DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')
                ->where('a.invoice_id', $invoice->id)->orderBy('p.payment_date')
                ->get(['p.id', 'p.payment_date', 'p.method', 'p.reference_number', 'p.status', 'a.amount', 'a.tds_amount']),
        ]);
    }

    public function addLine(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorizeDraftEdit($request, $invoice);
        /** @var array{line_type: string, description: string, quantity: string, unit_price: string} $data */
        $data = $request->validate([
            'line_type' => ['required', Rule::in(['professional_fee', 'time', 'event', 'retainer', 'expense'])],
            'description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999', 'decimal:0,2'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
        ]);
        $invoices->addLine($invoice, $data);

        return back()->with('status', 'Line added.');
    }

    public function removeLine(Request $request, Invoice $invoice, InvoiceLine $line, InvoiceService $invoices): RedirectResponse
    {
        $this->authorizeDraftEdit($request, $invoice);
        $invoices->removeLine($invoice, $line);

        return back()->with('status', 'Line removed.');
    }

    public function destroy(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorizeDraftEdit($request, $invoice);
        abort_unless($invoice->status === 'draft', 422);
        $invoices->deleteDraft($invoice);

        return redirect()->route('practice.invoices.index')->with('status', 'Draft removed.');
    }

    public function addTime(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorizeDraftEdit($request, $invoice);
        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*' => ['uuid'],
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
        ]);
        $count = $invoices->addTimeEntries($invoice, array_values($data['entries']), (string) $data['hourly_rate']);

        return back()->with('status', "{$count} time ".($count === 1 ? 'entry' : 'entries').' added.');
    }

    public function addExpenses(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorizeDraftEdit($request, $invoice);
        $data = $request->validate(['expenses' => ['required', 'array', 'min:1', 'max:200'], 'expenses.*' => ['uuid']]);
        $count = $invoices->addExpenses($invoice, array_values($data['expenses']));

        return back()->with('status', "{$count} ".($count === 1 ? 'expense' : 'expenses').' added.');
    }

    public function addScheduleItem(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorizeDraftEdit($request, $invoice);
        $data = $request->validate([
            'fee_schedule_item_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999', 'decimal:0,2'],
        ]);
        $item = FeeScheduleItem::query()->where(['id' => $data['fee_schedule_item_id'], 'tenant_id' => $invoice->tenant_id])->first();
        abort_if($item === null, 422, 'That fee is not available.');
        $invoices->addScheduleItem($invoice, $item, (string) $data['quantity']);

        return back()->with('status', 'Fee added.');
    }

    public function addRetainer(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorizeDraftEdit($request, $invoice);
        $data = $request->validate([
            'fee_arrangement_id' => ['required', 'uuid'],
            'period' => ['required', 'string', 'max:40'],
        ]);
        $arrangement = FeeArrangement::query()->where(['id' => $data['fee_arrangement_id'], 'tenant_id' => $invoice->tenant_id])->first();
        abort_if($arrangement === null, 422, 'That retainer is not available.');
        $invoices->addRetainer($invoice, $arrangement, $data['period']);

        return back()->with('status', 'Retainer added.');
    }

    /** Step 24. Refused with a plain message until a payment gateway is approved and set up. */
    public function paymentLink(Request $request, Invoice $invoice, PaymentGateway $gateway): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.payments.record'), 403);
        abort_unless($invoice->invoice_type !== 'credit_note' && in_array($invoice->status, ['issued', 'partially_paid'], true), 422, 'Only an issued invoice with a balance can have a payment link.');
        $link = $gateway->createPaymentLink($invoice);

        return back()->with('status', 'Payment link: '.$link['url']);
    }

    /** A credit note against this invoice. Provisional treatment, pending a CA's review. */
    public function creditNote(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.invoices.issue') && BillingAccess::canEditInvoice($user, $invoice), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $note = $invoices->issueCreditNote($invoice, $user, (string) $data['amount'], $data['reason']);

        return redirect()->route('practice.invoices.show', $note)->with('status', "Credit note {$note->invoice_number} issued.");
    }

    /** Private download only. Drafts have no number, and redacted viewers get no copy. */
    public function pdf(Request $request, Invoice $invoice, InvoiceService $invoices): Response
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_if($invoice->status === 'draft', 422, 'Issue the invoice first.');
        abort_if(BillingAccess::isRedacted($user, $invoice), 403);
        app(AuditLogWriter::class)->record('billing.invoice_downloaded', 'invoice', $invoice->id, $invoice->tenant_id);

        return response($invoices->pdf($invoice->load('lines')), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str_replace('/', '-', (string) $invoice->invoice_number).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function issue(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.invoices.issue') && BillingAccess::canEditInvoice($user, $invoice), 403);
        $issued = $invoices->issue($invoice);

        return back()->with('status', "Invoice {$issued->invoice_number} issued.");
    }

    public function cancel(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.invoices.cancel') && BillingAccess::canEditInvoice($user, $invoice), 403);
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);
        $invoices->cancel($invoice, $data['cancel_reason']);

        return back()->with('status', 'Invoice cancelled.');
    }

    /** D4.1: payments are allowed on redacted invoices, because receivables must reconcile. */
    public function recordPayment(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.payments.record'), 403);
        /** @var array{amount: string, payment_date: string, method: string, reference_number?: string|null, tds_amount?: string|null} $data */
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'tds_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(['cash', 'cheque', 'bank_transfer', 'upi', 'card'])],
            'reference_number' => ['nullable', 'string', 'max:80'],
        ]);
        $invoices->recordPayment($invoice, $user, $data);

        return back()->with('status', 'Payment recorded.');
    }

    public function reversePayment(Request $request, Payment $payment, InvoiceService $invoices): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($payment->tenant_id === AccessContext::currentTenantId(), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.payments.reverse'), 403);
        $invoices->reversePayment($payment);

        return back()->with('status', 'Payment reversed.');
    }

    /** Financial summary. Restricted matters appear as a single combined line (D4.1). */
    public function reports(Request $request): View
    {
        $tenant = AccessContext::currentTenantId();
        $sums = DB::table('invoices')->where('tenant_id', $tenant)->whereNull('deleted_at')->whereIn('status', ['issued', 'partially_paid', 'paid'])
            // Credit notes count against fees and tax, and the credit they apply isn't money received.
            ->selectRaw("COALESCE(SUM(CASE WHEN invoice_type = 'credit_note' THEN -subtotal ELSE subtotal END),0) fees,
                COALESCE(SUM(CASE WHEN invoice_type = 'credit_note' THEN -(cgst_amount+sgst_amount) ELSE cgst_amount+sgst_amount END),0) intra_tax,
                COALESCE(SUM(CASE WHEN invoice_type = 'credit_note' THEN -igst_amount ELSE igst_amount END),0) inter_tax,
                COALESCE(SUM(CASE WHEN invoice_type = 'credit_note' THEN -total_amount ELSE amount_paid END),0) received,
                COALESCE(SUM(balance_due),0) outstanding")->first();

        $today = now()->toDateString();
        $ageing = DB::table('invoices')->where('tenant_id', $tenant)->whereNull('deleted_at')->whereIn('status', ['issued', 'partially_paid'])
            ->selectRaw('COALESCE(SUM(CASE WHEN due_date IS NULL OR due_date >= ? THEN balance_due END),0) current_due,
                COALESCE(SUM(CASE WHEN due_date < ? AND DATEDIFF(?, due_date) <= 30 THEN balance_due END),0) d30,
                COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 31 AND 90 THEN balance_due END),0) d90,
                COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) > 90 THEN balance_due END),0) older', [$today, $today, $today, $today, $today])->first();

        return view('billing.reports', ['sums' => $sums, 'ageing' => $ageing, 'money' => Money::class]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * Work that can still be billed to this invoice's client, and matter if it has one.
     *
     * @return array<string, mixed>
     */
    private function billableSources(Invoice $invoice, bool $canEdit): array
    {
        if (! $canEdit) {
            return ['unbilledTime' => collect(), 'unbilledExpenses' => collect(), 'scheduleItems' => collect(), 'retainers' => collect(), 'defaultRate' => null];
        }

        $matters = Matter::query()->where(['tenant_id' => $invoice->tenant_id, 'client_id' => $invoice->client_id])
            ->when($invoice->matter_id !== null, fn ($q) => $q->whereKey($invoice->matter_id))->pluck('id');
        $arrangements = FeeArrangement::query()->with(['plan', 'scheduleItems'])->where(['tenant_id' => $invoice->tenant_id, 'client_id' => $invoice->client_id])
            ->where('status', '<>', 'ended')->get();
        $hourly = $arrangements->where('arrangement_type', 'hourly')->sortByDesc(fn (FeeArrangement $a) => $a->matter_id === $invoice->matter_id && $a->matter_id !== null)->first();

        return [
            'unbilledTime' => TimeEntry::query()->with('membership.user:id,name')->whereIn('matter_id', $matters)
                ->where(['status' => 'approved', 'is_billable' => true])->whereNull('invoice_line_id')->orderBy('work_date')->limit(200)->get(),
            'unbilledExpenses' => MatterExpense::query()->whereIn('matter_id', $matters)
                ->where(['is_reimbursable' => true])->whereNull('invoice_line_id')->orderBy('expense_date')->limit(200)->get(),
            'scheduleItems' => $arrangements->flatMap(fn (FeeArrangement $a) => $a->scheduleItems),
            'retainers' => $arrangements->filter(fn (FeeArrangement $a) => $a->isRetainer() && ($a->retainer_amount !== null || $a->plan !== null)),
            'defaultRate' => $hourly?->hourly_rate,
        ];
    }

    private function authorizeDraftEdit(Request $request, Invoice $invoice): void
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_unless(AccessContext::tenantCan($user, 'billing.invoices.create_draft') && BillingAccess::canEditInvoice($user, $invoice), 403);
    }
}
