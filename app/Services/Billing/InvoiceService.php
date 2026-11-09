<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\FeeArrangement;
use App\Models\FeeScheduleItem;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Matter;
use App\Models\MatterExpense;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\TaxRule;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AuditLogWriter;
use App\Support\Money;
use App\Support\SimplePdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * C16: drafts have no number, and a number is taken inside the issuing transaction
 * from a locked series row, so it's gap-free. C22: totals always reconcile.
 */
class InvoiceService
{
    public function __construct(private TaxEngine $tax) {}

    public function createDraft(Client $client, ?Matter $matter, ?string $dueDate, ?string $notes): Invoice
    {
        $invoice = new Invoice(['due_date' => $dueDate, 'notes' => $notes]);
        $invoice->forceFill([
            'tenant_id' => $client->tenant_id,
            'client_id' => $client->id,
            'matter_id' => $matter?->id,
            'place_of_supply' => $client->state_code,
            'status' => 'draft',
        ])->save();

        return $invoice->refresh();
    }

    /** @param array{line_type: string, description: string, quantity: string|float|int, unit_price: string|float|int} $data */
    public function addLine(Invoice $invoice, array $data): InvoiceLine
    {
        $this->assertDraft($invoice);

        return DB::transaction(function () use ($invoice, $data) {
            $line = new InvoiceLine($data);
            $amount = Money::multiply($data['quantity'], Money::toPaise($data['unit_price']));
            $line->forceFill([
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount' => Money::fromPaise($amount),
                'line_total' => Money::fromPaise($amount),
            ])->save();
            $this->recalculate($invoice);

            return $line;
        });
    }

    public function removeLine(Invoice $invoice, InvoiceLine $line): void
    {
        $this->assertDraft($invoice);
        abort_unless($line->invoice_id === $invoice->id, 404);

        DB::transaction(function () use ($invoice, $line) {
            $this->releaseSources([$line->id]);
            $line->delete();
            $this->recalculate($invoice);
        });
    }

    /** Drafts are removed with their lines' time and expenses released for billing again. */
    public function deleteDraft(Invoice $invoice): void
    {
        $this->assertDraft($invoice);

        DB::transaction(function () use ($invoice) {
            $this->releaseSources($invoice->lines()->pluck('id')->all());
            $invoice->delete();
        });
    }

    /**
     * One line per approved, billable, unbilled time entry, at the given hourly rate.
     * Amounts are worked out in paise from minutes, so no float is involved.
     *
     * @param  list<string>  $entryIds
     */
    public function addTimeEntries(Invoice $invoice, array $entryIds, string $hourlyRate): int
    {
        $this->assertDraft($invoice);
        $rate = Money::toPaise($hourlyRate);

        return DB::transaction(function () use ($invoice, $entryIds, $rate) {
            $entries = TimeEntry::query()->with('matter')->whereIn('id', $entryIds)->where('tenant_id', $invoice->tenant_id)->lockForUpdate()->get();
            if ($entries->count() !== count(array_unique($entryIds))) {
                throw ValidationException::withMessages(['entries' => 'One or more time entries are not available.']);
            }

            foreach ($entries as $entry) {
                if ($entry->status !== 'approved' || ! $entry->is_billable || $entry->invoice_line_id !== null || ! $this->belongsToInvoice($invoice, $entry->matter)) {
                    throw ValidationException::withMessages(['entries' => 'Only approved, billable, unbilled time for this client can be added.']);
                }

                $amount = intdiv($rate * $entry->minutes + 30, 60);
                $line = new InvoiceLine([
                    'line_type' => 'time',
                    'description' => mb_substr($entry->work_date->format('d M Y').' '.$entry->description.' ('.intdiv($entry->minutes, 60).'h '.($entry->minutes % 60).'m)', 0, 500),
                    'quantity' => Money::fromPaise(max(1, intdiv($entry->minutes * 100 + 30, 60))),
                    'unit_price' => Money::fromPaise($rate),
                ]);
                $line->forceFill(['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'amount' => Money::fromPaise($amount), 'line_total' => Money::fromPaise($amount)])->save();
                $entry->forceFill(['rate' => Money::fromPaise($rate), 'amount' => Money::fromPaise($amount), 'invoice_line_id' => $line->id, 'status' => 'billed'])->save();
            }
            $this->recalculate($invoice);

            return $entries->count();
        });
    }

    /**
     * Reimbursable expenses become lines. Pure-agent expenses are refused, because the
     * tax engine would tax them and their treatment needs a CA-approved rule.
     *
     * @param  list<string>  $expenseIds
     */
    public function addExpenses(Invoice $invoice, array $expenseIds): int
    {
        $this->assertDraft($invoice);

        return DB::transaction(function () use ($invoice, $expenseIds) {
            $expenses = MatterExpense::query()->with('matter')->whereIn('id', $expenseIds)->where('tenant_id', $invoice->tenant_id)->lockForUpdate()->get();
            if ($expenses->count() !== count(array_unique($expenseIds))) {
                throw ValidationException::withMessages(['expenses' => 'One or more expenses are not available.']);
            }

            foreach ($expenses as $expense) {
                if ($expense->is_pure_agent) {
                    throw ValidationException::withMessages(['expenses' => 'Pure-agent expenses cannot be invoiced until a tax rule for them is approved.']);
                }
                if (! $expense->is_reimbursable || $expense->invoice_line_id !== null || ! $this->belongsToInvoice($invoice, $expense->matter)) {
                    throw ValidationException::withMessages(['expenses' => 'Only reimbursable, unbilled expenses for this client can be added.']);
                }

                $line = new InvoiceLine([
                    'line_type' => 'expense',
                    'description' => mb_substr($expense->expense_date->format('d M Y').' '.$expense->category, 0, 500),
                    'quantity' => '1',
                    'unit_price' => $expense->amount,
                ]);
                $line->forceFill(['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'amount' => $expense->amount, 'line_total' => $expense->amount])->save();
                $expense->forceFill(['invoice_line_id' => $line->id])->save();
            }
            $this->recalculate($invoice);

            return $expenses->count();
        });
    }

    /** An event fee from the client's own fee schedule, such as one appearance. */
    public function addScheduleItem(Invoice $invoice, FeeScheduleItem $item, string $quantity = '1'): InvoiceLine
    {
        $this->assertDraft($invoice);
        $arrangement = $item->arrangement;
        if ($item->tenant_id !== $invoice->tenant_id || $arrangement === null || $arrangement->client_id !== $invoice->client_id || $arrangement->status === 'ended') {
            throw ValidationException::withMessages(['item' => 'That fee is not available for this invoice.']);
        }

        return DB::transaction(function () use ($invoice, $item, $quantity) {
            $amount = Money::multiply($quantity, Money::toPaise($item->amount));
            $line = new InvoiceLine(['line_type' => 'event', 'description' => $item->description, 'quantity' => $quantity, 'unit_price' => $item->amount]);
            $line->forceFill([
                'tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id,
                'fee_arrangement_id' => $item->fee_arrangement_id, 'fee_schedule_item_id' => $item->id,
                'amount' => Money::fromPaise($amount), 'line_total' => Money::fromPaise($amount),
            ])->save();
            $this->recalculate($invoice);

            return $line;
        });
    }

    /** One period of a retainer: the arrangement's own amount, or else its plan's monthly fee. */
    public function addRetainer(Invoice $invoice, FeeArrangement $arrangement, string $period): InvoiceLine
    {
        $this->assertDraft($invoice);
        $amount = $arrangement->retainer_amount ?? $arrangement->plan?->monthly_fee;
        if ($arrangement->tenant_id !== $invoice->tenant_id || $arrangement->client_id !== $invoice->client_id || ! $arrangement->isRetainer() || $arrangement->status === 'ended' || $amount === null) {
            throw ValidationException::withMessages(['arrangement' => 'That retainer is not available for this invoice.']);
        }

        return DB::transaction(function () use ($invoice, $arrangement, $period, $amount) {
            $name = $arrangement->plan !== null ? $arrangement->plan->name : 'Retainer';
            $line = new InvoiceLine(['line_type' => 'retainer', 'description' => mb_substr("{$name} for {$period}", 0, 500), 'quantity' => '1', 'unit_price' => (string) $amount]);
            $line->forceFill([
                'tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'fee_arrangement_id' => $arrangement->id,
                'amount' => (string) $amount, 'line_total' => (string) $amount,
            ])->save();
            $this->recalculate($invoice);

            return $line;
        });
    }

    public function issue(Invoice $invoice, string $prefix = 'INV'): Invoice
    {
        $this->assertDraft($invoice);

        return DB::transaction(function () use ($invoice, $prefix) {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);

            if ($locked->lines()->doesntExist()) {
                throw ValidationException::withMessages(['lines' => 'Add at least one line first.']);
            }

            $tenant = Tenant::query()->findOrFail($locked->tenant_id);
            /** @var Client $client */
            $client = $locked->client;
            $today = now();
            $rule = $this->tax->ruleFor($tenant, $client, $today->toDateString());

            foreach ($locked->lines as $line) {
                $this->applyRule($line, $rule);
            }

            $locked->forceFill([
                'invoice_type' => $rule->invoice_type,
                'charge_mechanism' => $rule->charge_mechanism,
                'invoice_number' => $this->nextNumber($locked->tenant_id, $prefix, $today),
                'issue_date' => $today->toDateString(),
                'issued_at' => $today,
                'status' => 'issued',
                'supplier_snapshot' => [
                    'name' => $tenant->legal_name ?? $tenant->name, 'gstin' => $tenant->gstin, 'state_code' => $tenant->state_code,
                    'address' => trim($tenant->address_line1.' '.$tenant->address_line2),
                ],
                'recipient_snapshot' => [
                    'name' => $client->name, 'gstin' => $client->gstin, 'state_code' => $client->state_code,
                    'address' => $client->address, 'client_type' => $client->client_type,
                ],
                'place_of_supply' => $client->state_code,
            ])->save();
            $this->recalculate($locked);
            app(AuditLogWriter::class)->record('billing.invoice_issued', 'invoice', $locked->id, $locked->tenant_id, ['status' => 'draft'], ['status' => 'issued']);

            return $locked->fresh() ?? $locked;
        });
    }

    /** Issued invoices keep their number and are never deleted (C16). */
    public function cancel(Invoice $invoice, string $reason): void
    {
        DB::transaction(function () use ($invoice, $reason) {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['issued', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['status' => 'Only an issued, unpaid or part-paid invoice can be cancelled.']);
            }
            if (Money::toPaise($locked->amount_paid) > 0) {
                throw ValidationException::withMessages(['status' => 'Reverse the payments on this invoice first.']);
            }
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            // The cancelled invoice keeps its lines; the work behind them can be billed again.
            $this->releaseSources($locked->lines()->pluck('id')->all());
            app(AuditLogWriter::class)->record('billing.invoice_cancelled', 'invoice', $locked->id, $locked->tenant_id, null, ['status' => 'cancelled']);
        });
    }

    /**
     * Records a payment and allocates it to one invoice, with the invoice row locked.
     *
     * @param  array{amount: string, payment_date: string, method: string, reference_number?: string|null, tds_amount?: string|null}  $data
     */
    public function recordPayment(Invoice $invoice, User $user, array $data): Payment
    {
        return DB::transaction(function () use ($invoice, $user, $data) {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['issued', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['amount' => 'This invoice cannot take a payment.']);
            }

            $amount = Money::toPaise($data['amount']);
            $tds = Money::toPaise($data['tds_amount'] ?? '0');
            if ($amount + $tds > Money::toPaise($locked->balance_due)) {
                throw ValidationException::withMessages(['amount' => 'This is more than the balance due.']);
            }

            $payment = new Payment(['amount' => $data['amount'], 'payment_date' => $data['payment_date'], 'method' => $data['method'], 'reference_number' => $data['reference_number'] ?? null]);
            $payment->forceFill(['tenant_id' => $locked->tenant_id, 'client_id' => $locked->client_id, 'status' => 'recorded', 'received_by' => $user->id])->save();

            $allocation = new PaymentAllocation;
            $allocation->forceFill([
                'tenant_id' => $locked->tenant_id, 'payment_id' => $payment->id, 'invoice_id' => $locked->id,
                'amount' => Money::fromPaise($amount), 'tds_amount' => Money::fromPaise($tds),
            ])->save();

            $this->applyPayments($locked);
            app(AuditLogWriter::class)->record('billing.payment_recorded', 'payment', $payment->id, $locked->tenant_id);

            return $payment;
        });
    }

    public function reversePayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'recorded') {
                throw ValidationException::withMessages(['payment' => 'Only a recorded payment can be reversed.']);
            }
            $locked->forceFill(['status' => 'reversed'])->save();
            app(AuditLogWriter::class)->record('billing.payment_reversed', 'payment', $locked->id, $locked->tenant_id);

            foreach ($locked->allocations()->pluck('invoice_id') as $invoiceId) {
                /** @var Invoice $invoice */
                $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
                $this->applyPayments($invoice);
            }
        });
    }

    private function applyRule(InvoiceLine $line, TaxRule $rule): void
    {
        $amount = Money::toPaise($line->amount);
        $cgst = Money::percentOf($amount, $rule->cgst_rate);
        $sgst = Money::percentOf($amount, $rule->sgst_rate);
        $igst = Money::percentOf($amount, $rule->igst_rate);

        // Under reverse charge the recipient pays the tax, so it isn't added to this invoice.
        $collected = $rule->charge_mechanism === 'forward' ? $cgst + $sgst + $igst : 0;

        $line->forceFill([
            'sac_code' => $rule->sac_code,
            'tax_rule_id' => $rule->id,
            // Frozen, so later rule edits never change an issued invoice.
            'tax_rule_snapshot' => $rule->only(['code', 'name', 'charge_mechanism', 'cgst_rate', 'sgst_rate', 'igst_rate', 'invoice_type', 'legal_reference', 'effective_from']),
            'charge_mechanism' => $rule->charge_mechanism,
            'cgst_rate' => $rule->cgst_rate, 'cgst_amount' => Money::fromPaise($rule->charge_mechanism === 'forward' ? $cgst : 0),
            'sgst_rate' => $rule->sgst_rate, 'sgst_amount' => Money::fromPaise($rule->charge_mechanism === 'forward' ? $sgst : 0),
            'igst_rate' => $rule->igst_rate, 'igst_amount' => Money::fromPaise($rule->charge_mechanism === 'forward' ? $igst : 0),
            'line_total' => Money::fromPaise($amount + $collected),
        ])->save();
    }

    private function recalculate(Invoice $invoice): void
    {
        $invoice->refresh();
        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->get();
        $sum = fn (string $field) => $lines->sum(fn (InvoiceLine $l) => Money::toPaise($l->{$field}));

        $subtotal = $sum('amount');
        $cgst = $sum('cgst_amount');
        $sgst = $sum('sgst_amount');
        $igst = $sum('igst_amount');
        $tax = $cgst + $sgst + $igst;
        $total = $subtotal + $tax;
        $paid = Money::toPaise($invoice->amount_paid);
        $tds = Money::toPaise($invoice->tds_deducted);

        $invoice->forceFill([
            'subtotal' => Money::fromPaise($subtotal),
            'cgst_amount' => Money::fromPaise($cgst), 'sgst_amount' => Money::fromPaise($sgst), 'igst_amount' => Money::fromPaise($igst),
            'total_tax' => Money::fromPaise($tax),
            'round_off' => '0.00',
            'total_amount' => Money::fromPaise($total),
            'balance_due' => Money::fromPaise($total - $paid - $tds),
        ])->save();
    }

    private function applyPayments(Invoice $invoice): void
    {
        $rows = DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.invoice_id', $invoice->id)->where('p.status', 'recorded')->get(['a.amount', 'a.tds_amount']);
        $paid = $rows->sum(fn ($r) => Money::toPaise($r->amount));
        $tds = $rows->sum(fn ($r) => Money::toPaise($r->tds_amount));
        $balance = Money::toPaise($invoice->total_amount) - $paid - $tds;

        $invoice->forceFill([
            'amount_paid' => Money::fromPaise($paid),
            'tds_deducted' => Money::fromPaise($tds),
            'balance_due' => Money::fromPaise($balance),
            'status' => $balance === 0 ? 'paid' : ($paid + $tds > 0 ? 'partially_paid' : 'issued'),
        ])->save();
    }

    private function nextNumber(string $tenantId, string $prefix, Carbon $date): string
    {
        $startYear = $date->month >= 4 ? $date->year : $date->year - 1;
        $financialYear = sprintf('%d-%02d', $startYear, ($startYear + 1) % 100);

        DB::table('invoice_series')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'prefix' => $prefix, 'financial_year' => $financialYear,
            'next_number' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $series = DB::table('invoice_series')->where(['tenant_id' => $tenantId, 'prefix' => $prefix, 'financial_year' => $financialYear])->lockForUpdate()->first();
        DB::table('invoice_series')->where('id', $series->id)->update(['next_number' => $series->next_number + 1, 'updated_at' => now()]);

        $number = sprintf('%s/%02d%02d/%d', $prefix, $startYear % 100, ($startYear + 1) % 100, $series->next_number);
        if (strlen($number) > 16) {
            throw ValidationException::withMessages(['prefix' => 'That prefix makes the invoice number longer than 16 characters.']);
        }

        return $number;
    }

    /** A plain PDF of an issued invoice. Not stored, so no copy outlives the invoice record. */
    public function pdf(Invoice $invoice): string
    {
        $rs = fn ($amount): string => str_replace('₹', 'Rs. ', Money::format($amount));
        $supplier = $invoice->supplier_snapshot ?? [];
        $recipient = $invoice->recipient_snapshot ?? [];
        $label = $invoice->invoice_type === 'bill_of_supply' ? 'Bill of supply' : 'Tax invoice';

        $paragraphs = [
            "{$label} {$invoice->invoice_number}".($invoice->status === 'cancelled' ? ' (CANCELLED)' : ''),
            'Issued: '.$invoice->issue_date?->format('d M Y').($invoice->due_date ? '    Due: '.$invoice->due_date->format('d M Y') : ''),
            'From: '.($supplier['name'] ?? '').(empty($supplier['gstin']) ? '' : '    GSTIN: '.$supplier['gstin']).(empty($supplier['address']) ? '' : "\n".$supplier['address']),
            'Bill to: '.($recipient['name'] ?? '').(empty($recipient['gstin']) ? '' : '    GSTIN: '.$recipient['gstin']).(empty($recipient['address']) ? '' : "\n".$recipient['address']),
            'Place of supply (state code): '.$invoice->place_of_supply,
        ];
        foreach ($invoice->lines as $i => $line) {
            $tax = Money::toPaise($line->cgst_amount) + Money::toPaise($line->sgst_amount) + Money::toPaise($line->igst_amount);
            $paragraphs[] = ($i + 1).'. '.$line->description."\n   SAC {$line->sac_code}   Qty {$line->quantity} x ".$rs($line->unit_price).' = '.$rs($line->amount).'   Tax '.$rs(Money::fromPaise($tax));
        }
        $paragraphs[] = implode("\n", [
            'Taxable value: '.$rs($invoice->subtotal),
            'CGST: '.$rs($invoice->cgst_amount).'   SGST: '.$rs($invoice->sgst_amount).'   IGST: '.$rs($invoice->igst_amount),
            'Total: '.$rs($invoice->total_amount),
            'Paid: '.$rs($invoice->amount_paid).'   TDS: '.$rs($invoice->tds_deducted).'   Balance due: '.$rs($invoice->balance_due),
        ]);
        if ($invoice->charge_mechanism === 'reverse') {
            $paragraphs[] = 'Tax on this invoice is payable by the recipient under reverse charge.';
        }
        if ($invoice->notes) {
            $paragraphs[] = 'Notes: '.$invoice->notes;
        }

        return SimplePdf::render("{$label} {$invoice->invoice_number}", $paragraphs);
    }

    /** The source's matter must be the invoice's client, and its matter if the invoice has one. */
    private function belongsToInvoice(Invoice $invoice, ?Matter $matter): bool
    {
        return $matter !== null && $matter->tenant_id === $invoice->tenant_id && $matter->client_id === $invoice->client_id
            && ($invoice->matter_id === null || $invoice->matter_id === $matter->id);
    }

    /** @param  list<string>  $lineIds */
    private function releaseSources(array $lineIds): void
    {
        if ($lineIds === []) {
            return;
        }
        TimeEntry::query()->whereIn('invoice_line_id', $lineIds)->update(['invoice_line_id' => null, 'status' => 'approved', 'rate' => null, 'amount' => null]);
        MatterExpense::query()->whereIn('invoice_line_id', $lineIds)->update(['invoice_line_id' => null]);
    }

    private function assertDraft(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft invoice can be changed.']);
        }
    }
}
