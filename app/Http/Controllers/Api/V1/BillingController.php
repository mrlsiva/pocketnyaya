<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Support\AccessContext;
use App\Support\BillingAccess;
use App\Support\MatterAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The same rules as the web screens, including redaction for restricted matters (DA-29). */
class BillingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = Invoice::query()->where('tenant_id', AccessContext::currentTenantId())->latest()->paginate(25);

        return response()->json($page->through(fn (Invoice $i) => $this->summary($i)));
    }

    public function store(Request $request, InvoiceService $invoices): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::tenantCan($user, 'billing.invoices.create_draft'), 403);
        $data = $request->validate(['client_id' => ['required', 'uuid'], 'matter_id' => ['nullable', 'uuid'], 'due_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $tenant = AccessContext::currentTenantId();
        $client = Client::query()->where(['id' => $data['client_id'], 'tenant_id' => $tenant])->first();
        abort_if($client === null, 422, 'That client is not available.');
        $matter = empty($data['matter_id']) ? null : Matter::query()->where(['id' => $data['matter_id'], 'tenant_id' => $tenant, 'client_id' => $client->id])->first();
        abort_if(! empty($data['matter_id']) && $matter === null, 422, 'That matter is not available.');
        abort_unless(BillingAccess::canSeeMatterBilling($user, $matter), 404);

        return response()->json($this->summary($invoices->createDraft($client, $matter, $data['due_date'] ?? null, $data['notes'] ?? null)), 201);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        $redacted = BillingAccess::isRedacted($user, $invoice);

        return response()->json($this->summary($invoice) + [
            'redacted' => $redacted,
            'matter_id' => $redacted ? null : $invoice->matter_id,
            'lines' => $invoice->lines()->get()->map(fn (InvoiceLine $l) => [
                'id' => $l->id,
                'description' => $redacted ? null : $l->description,
                'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'amount' => $l->amount,
                'cgst_amount' => $l->cgst_amount, 'sgst_amount' => $l->sgst_amount, 'igst_amount' => $l->igst_amount,
            ]),
        ]);
    }

    public function addLine(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeEdit($request, $invoice, 'billing.invoices.create_draft');
        /** @var array{line_type: string, description: string, quantity: string, unit_price: string} $data */
        $data = $request->validate([
            'line_type' => ['required', Rule::in(['professional_fee', 'time', 'event', 'retainer', 'expense'])],
            'description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        $invoices->addLine($invoice, array_map('strval', $data));

        return response()->json($this->summary($invoice->fresh() ?? $invoice), 201);
    }

    public function issue(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeEdit($request, $invoice, 'billing.invoices.issue');

        return response()->json($this->summary($invoices->issue($invoice)));
    }

    public function pay(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
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
        $data['amount'] = (string) $data['amount'];
        $data['tds_amount'] = isset($data['tds_amount']) ? (string) $data['tds_amount'] : null;
        $invoices->recordPayment($invoice, $user, $data);

        return response()->json($this->summary($invoice->fresh() ?? $invoice), 201);
    }

    public function time(Request $request): JsonResponse
    {
        $page = TimeEntry::query()->where('tenant_id', AccessContext::currentTenantId())->where('membership_id', MatterAccess::me($this->user($request)))->latest('work_date')->paginate(25);

        return response()->json($page->through(fn (TimeEntry $t) => $t->only(['id', 'matter_id', 'work_date', 'minutes', 'description', 'is_billable', 'status'])));
    }

    public function logTime(Request $request): JsonResponse
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

        return response()->json($entry->only(['id', 'matter_id', 'minutes', 'status']), 201);
    }

    /** @return array<string, mixed> */
    private function summary(Invoice $invoice): array
    {
        return $invoice->only(['id', 'invoice_number', 'status', 'client_id', 'issue_date', 'due_date', 'subtotal', 'cgst_amount', 'sgst_amount', 'igst_amount', 'total_tax', 'total_amount', 'amount_paid', 'tds_deducted', 'balance_due']);
    }

    private function authorizeEdit(Request $request, Invoice $invoice, string $permission): void
    {
        $user = $this->user($request);
        abort_unless(BillingAccess::canViewInvoice($user, $invoice), 404);
        abort_unless(AccessContext::tenantCan($user, $permission) && BillingAccess::canEditInvoice($user, $invoice), 403);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
