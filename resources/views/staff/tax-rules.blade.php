@extends('layouts.app')
@section('title', 'Tax rules')

@section('content')
    <h1 class="h4 mb-1">Platform tax rules</h1>
    <p class="small text-body-secondary">Rules take effect only after a different person records a qualified accountant's approval.</p>
    <div class="row g-3">
        <div class="col-lg-8"><div class="card shadow-sm"><div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Rule</th><th>Mechanism</th><th>CGST / SGST / IGST</th><th>From</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rules as $rule)
                        <tr>
                            <td>{{ $rule->name }}<div class="small text-body-secondary">{{ $rule->code }}</div></td>
                            <td class="text-capitalize">{{ $rule->charge_mechanism }}</td>
                            <td class="text-nowrap">{{ $rule->cgst_rate }} / {{ $rule->sgst_rate }} / {{ $rule->igst_rate }}</td>
                            <td class="text-nowrap">{{ $rule->effective_from->format('d M Y') }}</td>
                            <td>{{ $rule->approved_at ? 'Approved' : 'Draft' }}</td>
                            <td>
                                @if (! $rule->approved_at && $rule->drafted_by !== auth()->id())
                                    <form method="post" action="{{ route('staff.tax.approve', $rule) }}">@csrf<button class="btn btn-outline-primary btn-sm" type="submit">Record approval</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-body-secondary">No rules yet. Invoices can't be issued until one is approved.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div></div>
        <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Draft a rule</h2>
            <form method="post" action="{{ route('staff.tax.store') }}">@csrf
                <input class="form-control form-control-sm mb-2" name="code" placeholder="CODE" required aria-label="Code">
                <input class="form-control form-control-sm mb-2" name="name" placeholder="Name" required aria-label="Name">
                <input class="form-control form-control-sm mb-2" name="sac_code" value="9982" required aria-label="SAC code">
                <select class="form-select form-select-sm mb-2" name="supplier_registration" aria-label="Supplier registration"><option value="any">Any supplier</option><option value="registered">Registered supplier</option><option value="unregistered">Unregistered supplier</option></select>
                <select class="form-select form-select-sm mb-2" name="recipient_type" aria-label="Recipient"><option value="any">Any recipient</option><option value="individual">Individual</option><option value="business">Business</option><option value="government">Government</option></select>
                <select class="form-select form-select-sm mb-2" name="recipient_turnover" aria-label="Recipient turnover"><option value="any">Any turnover</option><option value="below_threshold">Below threshold</option><option value="above_threshold">Above threshold</option></select>
                <select class="form-select form-select-sm mb-2" name="supply_type" aria-label="Supply type"><option value="any">Any supply</option><option value="intra_state">Intra-state</option><option value="inter_state">Inter-state</option></select>
                <select class="form-select form-select-sm mb-2" name="charge_mechanism" aria-label="Charge mechanism"><option value="forward">Forward charge</option><option value="reverse">Reverse charge</option><option value="exempt">Exempt</option><option value="nil">Nil</option></select>
                <div class="row g-1 mb-2">
                    <div class="col"><input class="form-control form-control-sm" name="cgst_rate" value="0" aria-label="CGST rate"></div>
                    <div class="col"><input class="form-control form-control-sm" name="sgst_rate" value="0" aria-label="SGST rate"></div>
                    <div class="col"><input class="form-control form-control-sm" name="igst_rate" value="0" aria-label="IGST rate"></div>
                </div>
                <select class="form-select form-select-sm mb-2" name="invoice_type" aria-label="Invoice type"><option value="tax_invoice">Tax invoice</option><option value="bill_of_supply">Bill of supply</option></select>
                <input class="form-control form-control-sm mb-2" name="priority" type="number" value="0" aria-label="Priority">
                <input class="form-control form-control-sm mb-2" name="effective_from" type="date" required aria-label="Effective from">
                <textarea class="form-control form-control-sm mb-2" name="legal_reference" rows="2" placeholder="Legal reference" required aria-label="Legal reference"></textarea>
                <button class="btn btn-primary btn-sm" type="submit">Save draft</button>
            </form>
        </div></div></div>
    </div>
@endsection
