@extends('layouts.app')
@section('title', $invoice->invoice_number ?? 'Draft invoice')

@section('content')
    @php($money = \App\Support\Money::class)
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3 d-print-none">
        <h1 class="h4 mb-0">{{ $invoice->invoice_number ?? 'Draft invoice' }} <span class="badge text-bg-light text-capitalize">{{ str_replace('_', ' ', $invoice->status) }}</span></h1>
        <div class="d-flex flex-wrap gap-2">
            @if ($invoice->status !== 'draft' && ! $redacted)
                <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">Print</button>
            @endif
            @if ($canIssue)
                <form method="post" action="{{ route('practice.invoices.issue', $invoice) }}">@csrf<button class="btn btn-primary btn-sm" type="submit">Issue invoice</button></form>
            @endif
            @if ($canEdit)
                <form method="post" action="{{ route('practice.invoices.destroy', $invoice) }}">@csrf @method('delete')<button class="btn btn-outline-danger btn-sm" type="submit">Delete draft</button></form>
            @endif
        </div>
    </div>
    <div class="d-print-none">@include('practice.nav')</div>

    @if ($redacted)
        <div class="alert alert-secondary small">This matter is restricted. Line descriptions and matter details are hidden.</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <div class="row">
                    <div class="col-sm-6">
                        <div class="small text-body-secondary">Bill to</div>
                        <div class="fw-semibold">{{ $invoice->recipient_snapshot['name'] ?? $invoice->client?->name }}</div>
                        <div class="small">{{ $invoice->recipient_snapshot['gstin'] ?? $invoice->client?->gstin }}</div>
                    </div>
                    <div class="col-sm-6 text-sm-end">
                        @if ($invoice->issue_date)<div class="small">Issued {{ $invoice->issue_date->format('d M Y') }}</div>@endif
                        @if ($invoice->due_date)<div class="small">Due {{ $invoice->due_date->format('d M Y') }}</div>@endif
                        @if (! $redacted && $invoice->matter)<div class="small">{{ $invoice->matter->matter_number }}</div>@endif
                        <div class="small text-capitalize">{{ str_replace('_', ' ', $invoice->invoice_type) }} · {{ $invoice->charge_mechanism }} charge</div>
                    </div>
                </div>
            </div></div>

            <div class="card shadow-sm mb-3"><div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Amount</th><th class="text-end">Tax</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($invoice->lines as $line)
                            <tr>
                                <td>{{ $redacted ? 'Hidden' : $line->description }}</td>
                                <td class="text-end">{{ $line->quantity }}</td>
                                <td class="text-end text-nowrap">{{ $money::format($line->unit_price) }}</td>
                                <td class="text-end text-nowrap">{{ $money::format($line->amount) }}</td>
                                <td class="text-end text-nowrap">{{ $money::format($money::fromPaise($money::toPaise($line->cgst_amount) + $money::toPaise($line->sgst_amount) + $money::toPaise($line->igst_amount))) }}</td>
                                <td class="text-end">
                                    @if ($canEdit)
                                        <form method="post" action="{{ route('practice.invoices.lines.destroy', [$invoice, $line]) }}">@csrf @method('delete')<button class="btn btn-link btn-sm p-0" type="submit">Remove</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-body-secondary">No lines yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div></div>

            @if ($invoice->status === 'draft')
                <p class="small text-body-secondary">Tax is worked out from the approved tax rule when the invoice is issued.</p>
            @endif
            @if ($invoice->charge_mechanism === 'reverse')
                <p class="small">Tax on this invoice is payable by the recipient under reverse charge.</p>
            @endif

            @if ($canEdit)
                <div class="card shadow-sm d-print-none"><div class="card-body">
                    <h2 class="h6">Add a line</h2>
                    <form class="row g-2" method="post" action="{{ route('practice.invoices.lines.store', $invoice) }}">@csrf
                        <div class="col-md-3"><select class="form-select form-select-sm" name="line_type" aria-label="Line type">
                            <option value="professional_fee">Professional fee</option><option value="time">Time</option><option value="event">Event</option><option value="retainer">Retainer</option><option value="expense">Expense</option>
                        </select></div>
                        <div class="col-md-9"><input class="form-control form-control-sm" name="description" placeholder="Description" required></div>
                        <div class="col-md-3"><input class="form-control form-control-sm" name="quantity" value="1" required aria-label="Quantity"></div>
                        <div class="col-md-3"><input class="form-control form-control-sm" name="unit_price" placeholder="Rate" required aria-label="Rate"></div>
                        <div class="col-md-3"><button class="btn btn-primary btn-sm" type="submit">Add line</button></div>
                    </form>
                </div></div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-7">Taxable value</dt><dd class="col-5 text-end">{{ $money::format($invoice->subtotal) }}</dd>
                    <dt class="col-7">CGST</dt><dd class="col-5 text-end">{{ $money::format($invoice->cgst_amount) }}</dd>
                    <dt class="col-7">SGST</dt><dd class="col-5 text-end">{{ $money::format($invoice->sgst_amount) }}</dd>
                    <dt class="col-7">IGST</dt><dd class="col-5 text-end">{{ $money::format($invoice->igst_amount) }}</dd>
                    <dt class="col-7">Total</dt><dd class="col-5 text-end fw-semibold">{{ $money::format($invoice->total_amount) }}</dd>
                    <dt class="col-7">Paid</dt><dd class="col-5 text-end">{{ $money::format($invoice->amount_paid) }}</dd>
                    <dt class="col-7">TDS</dt><dd class="col-5 text-end">{{ $money::format($invoice->tds_deducted) }}</dd>
                    <dt class="col-7">Balance due</dt><dd class="col-5 text-end fw-semibold mb-0">{{ $money::format($invoice->balance_due) }}</dd>
                </dl>
            </div></div>

            @if ($payments->isNotEmpty())
                <div class="card shadow-sm mb-3 d-print-none"><div class="card-body">
                    <h2 class="h6">Payments</h2>
                    @foreach ($payments as $payment)
                        <div class="d-flex justify-content-between small py-1 border-bottom">
                            <span>{{ \Illuminate\Support\Carbon::parse($payment->payment_date)->format('d M Y') }} · {{ str_replace('_', ' ', $payment->method) }} · {{ $money::format($payment->amount) }}
                                @if ($payment->status !== 'recorded')<span class="badge text-bg-secondary">{{ $payment->status }}</span>@endif</span>
                            @if ($canReverse && $payment->status === 'recorded')
                                <form method="post" action="{{ route('practice.payments.reverse', $payment->id) }}">@csrf<button class="btn btn-link btn-sm p-0" type="submit">Reverse</button></form>
                            @endif
                        </div>
                    @endforeach
                </div></div>
            @endif

            @if ($canPay)
                <div class="card shadow-sm mb-3 d-print-none"><div class="card-body">
                    <h2 class="h6">Record a payment</h2>
                    <form method="post" action="{{ route('practice.invoices.payments.store', $invoice) }}">@csrf
                        <input class="form-control form-control-sm mb-2" name="amount" placeholder="Amount" required aria-label="Amount">
                        <input class="form-control form-control-sm mb-2" name="tds_amount" placeholder="TDS deducted (optional)" aria-label="TDS deducted">
                        <input class="form-control form-control-sm mb-2" name="payment_date" type="date" value="{{ now()->toDateString() }}" required aria-label="Payment date">
                        <select class="form-select form-select-sm mb-2" name="method" aria-label="Method">
                            <option value="bank_transfer">Bank transfer</option><option value="upi">UPI</option><option value="cheque">Cheque</option><option value="cash">Cash</option><option value="card">Card</option>
                        </select>
                        <input class="form-control form-control-sm mb-2" name="reference_number" placeholder="Reference (optional)" aria-label="Reference">
                        <button class="btn btn-primary btn-sm" type="submit">Record</button>
                    </form>
                </div></div>
            @endif

            @if ($canCancel)
                <div class="card shadow-sm d-print-none"><div class="card-body">
                    <h2 class="h6">Cancel invoice</h2>
                    <form method="post" action="{{ route('practice.invoices.cancel', $invoice) }}">@csrf
                        <input class="form-control form-control-sm mb-2" name="cancel_reason" placeholder="Reason" required aria-label="Reason">
                        <button class="btn btn-outline-danger btn-sm" type="submit">Cancel invoice</button>
                    </form>
                </div></div>
            @endif
        </div>
    </div>
@endsection
