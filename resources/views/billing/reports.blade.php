@extends('layouts.app')
@section('title', 'Financial reports')

@section('content')
    <h1 class="h4 mb-3">Financial reports</h1>
    @include('practice.nav')
    @include('billing.nav')
    <div class="row g-3">
        @foreach ([['Fees billed', $sums->fees], ['CGST and SGST collected', $sums->intra_tax], ['IGST collected', $sums->inter_tax], ['Received', $sums->received], ['Outstanding', $sums->outstanding]] as [$label, $value])
            <div class="col-sm-6 col-lg-4"><div class="card shadow-sm"><div class="card-body">
                <div class="small text-body-secondary">{{ $label }}</div><div class="h4 mb-0">{{ $money::format($value) }}</div>
            </div></div></div>
        @endforeach
    </div>
    <div class="card shadow-sm mt-3"><div class="card-body">
        <h2 class="h6">Receivables by age</h2>
        <table class="table table-sm mb-0">
            <tbody>
                <tr><td>Not yet due</td><td class="text-end">{{ $money::format($ageing->current_due) }}</td></tr>
                <tr><td>Up to 30 days overdue</td><td class="text-end">{{ $money::format($ageing->d30) }}</td></tr>
                <tr><td>31 to 90 days overdue</td><td class="text-end">{{ $money::format($ageing->d90) }}</td></tr>
                <tr><td>Over 90 days overdue</td><td class="text-end">{{ $money::format($ageing->older) }}</td></tr>
            </tbody>
        </table>
    </div></div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4 mb-2">
        <h2 class="h5 mb-0">Financial year {{ $year }}–{{ substr((string) ($year + 1), -2) }}</h2>
        <form method="get" class="d-flex gap-1"><input class="form-control form-control-sm" style="width: 6rem" name="fy" type="number" value="{{ $year }}" aria-label="Financial year starting"><button class="btn btn-outline-secondary btn-sm" type="submit">Show</button></form>
    </div>
    <div class="row g-3">
        <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-body">
            <h3 class="h6">Billable hours</h3>
            <div class="table-responsive"><table class="table table-sm small mb-0">
                <thead><tr><th>Member</th><th class="text-end">Recorded</th><th class="text-end">Billable</th><th class="text-end">Billed</th><th class="text-end">Utilisation</th></tr></thead>
                <tbody>
                    @forelse ($hours as $row)
                        <tr><td>{{ $row->name }}</td>
                            @foreach ([$row->total_minutes, $row->billable_minutes, $row->billed_minutes] as $m)<td class="text-end text-nowrap">{{ intdiv($m, 60) }}h {{ $m % 60 }}m</td>@endforeach
                            <td class="text-end">{{ $row->utilisation === null ? '—' : $row->utilisation.'%' }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="text-body-secondary">No time recorded in this year.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            <p class="small text-body-secondary mt-2 mb-0">Utilisation is the share of recorded time that is billable.</p>
        </div></div></div>
        <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-body">
            <h3 class="h6">Fees by lead advocate</h3>
            <div class="table-responsive"><table class="table table-sm small mb-0">
                <thead><tr><th>Lead</th><th class="text-end">Invoices</th><th class="text-end">Fees billed</th><th class="text-end">Collected</th></tr></thead>
                <tbody>
                    @forelse ($leads as $row)
                        <tr><td>{{ $row->lead }}</td><td class="text-end">{{ $row->invoices }}</td><td class="text-end text-nowrap">{{ $money::format($row->fees) }}</td><td class="text-end text-nowrap">{{ $money::format($row->collected) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="text-body-secondary">No invoices issued in this year.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            <p class="small text-body-secondary mt-2 mb-0">Fees exclude GST, and credit notes are deducted. Restricted matters you can't open are combined into one line.</p>
        </div></div></div>
    </div>
    <p class="small text-body-secondary mt-3">These figures help you manage the practice. They aren't a tax filing, so please have an accountant review them.</p>
@endsection
