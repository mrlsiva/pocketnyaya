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
    <p class="small text-body-secondary mt-3">These figures help you manage the practice. They aren't a tax filing, so please have an accountant review them.</p>
@endsection
