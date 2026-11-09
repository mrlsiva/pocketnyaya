@extends('layouts.app')
@section('title', 'Invoices')

@section('content')
    <h1 class="h4 mb-3">Invoices</h1>
    @include('practice.nav')
    @include('billing.nav')
    <div class="row g-3">
        <div class="col-lg-8">
            <form class="mb-2" method="get"><select class="form-select form-select-sm w-auto" name="status" aria-label="Filter by status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach (['draft', 'issued', 'partially_paid', 'paid', 'cancelled'] as $option)<option value="{{ $option }}" @selected($status === $option)>{{ ucfirst(str_replace('_', ' ', $option)) }}</option>@endforeach
            </select></form>
            <div class="card shadow-sm"><div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Number</th><th>Client</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                        @forelse ($invoices as $invoice)
                            <tr>
                                <td><a href="{{ route('practice.invoices.show', $invoice) }}">{{ $invoice->invoice_number ?? 'Draft' }}</a></td>
                                <td>{{ $invoice->client?->name }}</td>
                                <td class="text-capitalize">{{ str_replace('_', ' ', $invoice->status) }}</td>
                                <td class="text-end text-nowrap">{{ \App\Support\Money::format($invoice->total_amount) }}</td>
                                <td class="text-end text-nowrap">{{ \App\Support\Money::format($invoice->balance_due) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-body-secondary">No invoices yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div></div>
            <div class="mt-3">{{ $invoices->links('pagination::bootstrap-5') }}</div>
        </div>
        @if ($canCreate)
            <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">New draft invoice</h2>
                <form method="post" action="{{ route('practice.invoices.store') }}">@csrf
                    <div class="mb-2"><label class="form-label small" for="client_id">Client</label>
                        <select class="form-select form-select-sm" id="client_id" name="client_id" required><option value="">Choose…</option>
                            @foreach ($clients as $client)<option value="{{ $client->id }}">{{ $client->name }}</option>@endforeach
                        </select></div>
                    <div class="mb-2"><label class="form-label small" for="due_date">Due date</label>
                        <input class="form-control form-control-sm" id="due_date" name="due_date" type="date"></div>
                    <button class="btn btn-primary btn-sm" type="submit">Create draft</button>
                </form>
            </div></div></div>
        @endif
    </div>
@endsection
