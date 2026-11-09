@extends('layouts.app')
@section('title', 'Retention rules')

@section('content')
    <h1 class="h4 mb-1">Retention rules</h1>
    <p class="small text-body-secondary">A rule takes effect only after a different person records counsel's approval.</p>
    @include('staff.privacy.nav')
    <div class="row g-3">
        <div class="col-lg-8"><div class="card shadow-sm"><div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Category</th><th>Keep for</th><th>From</th><th>Then</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rules as $rule)
                        <tr>
                            <td>{{ $rule->data_category }} v{{ $rule->version }}</td>
                            <td>{{ $rule->retention_months === null ? 'No limit' : $rule->retention_months.' months' }}</td>
                            <td>{{ str_replace('_', ' ', $rule->trigger_event) }}</td>
                            <td>{{ $rule->on_expiry }}</td>
                            <td class="text-capitalize">{{ $rule->status }}</td>
                            <td>
                                @if ($rule->status === 'draft' && $rule->drafted_by !== auth()->id() && \App\Support\AccessContext::staffCan(auth()->user(), 'dpdp.retention_rules.record_approval'))
                                    <form class="d-flex gap-1" method="post" action="{{ route('staff.privacy.retention.approve', $rule) }}">@csrf
                                        <input class="form-control form-control-sm" name="approval_evidence" placeholder="Counsel's approval reference" required aria-label="Approval reference">
                                        <button class="btn btn-outline-primary btn-sm" type="submit">Record</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-body-secondary">No rules yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div></div>
        @if (\App\Support\AccessContext::staffCan(auth()->user(), 'dpdp.retention_rules.draft'))
            <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Draft a rule</h2>
                <form method="post" action="{{ route('staff.privacy.retention.store') }}">@csrf
                    <input class="form-control form-control-sm mb-2" name="data_category" placeholder="data_category" required aria-label="Data category">
                    <input class="form-control form-control-sm mb-2" name="retention_months" type="number" placeholder="Months to keep" aria-label="Months to keep">
                    <select class="form-select form-select-sm mb-2" name="trigger_event" aria-label="Counted from">@foreach (['account_closure', 'matter_closure', 'financial_year_end', 'creation', 'settlement_execution', 'request_closure'] as $o)<option value="{{ $o }}">{{ ucfirst(str_replace('_', ' ', $o)) }}</option>@endforeach</select>
                    <select class="form-select form-select-sm mb-2" name="on_expiry" aria-label="When it expires">@foreach (['review', 'delete', 'anonymize', 'archive'] as $o)<option value="{{ $o }}">{{ ucfirst($o) }} on expiry</option>@endforeach</select>
                    <select class="form-select form-select-sm mb-2" name="on_erasure_request" aria-label="On an erasure request">@foreach (['retain', 'erase', 'anonymize'] as $o)<option value="{{ $o }}">{{ ucfirst($o) }} on erasure request</option>@endforeach</select>
                    <textarea class="form-control form-control-sm mb-2" name="legal_basis_note" rows="2" placeholder="Legal basis" required aria-label="Legal basis"></textarea>
                    <button class="btn btn-primary btn-sm" type="submit">Save draft</button>
                </form>
            </div></div></div>
        @endif
    </div>
@endsection
