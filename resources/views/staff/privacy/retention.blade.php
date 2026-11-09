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
        <div class="col-lg-8 order-lg-last"><div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Disposal batches</h2>
            <p class="small text-body-secondary">Each night the report lists what approved rules make due, without changing anything. One person proposes a batch, and a different person approves it. Holds and due dates are checked again when it runs.</p>
            @forelse ($batches as $batch)
                <div class="d-flex flex-wrap justify-content-between gap-2 py-2 border-bottom small">
                    <div>
                        <code>{{ $batch->data_category }}</code> · {{ $batch->item_count }} item(s) · {{ $batch->action }}
                        <span class="badge text-bg-light text-capitalize">{{ $batch->status }}</span>
                        <div class="text-body-secondary">Reported {{ \Illuminate\Support\Carbon::parse($batch->reported_at)->format('d M Y H:i') }}
                            @if ($batch->summary) · Result: {{ collect(json_decode($batch->summary, true))->map(fn ($v, $k) => str_replace('_', ' ', $k).' '.$v)->implode(', ') }}@endif</div>
                    </div>
                    <div class="d-flex gap-1">
                        @if ($batch->status === 'report' && \App\Support\AccessContext::staffCan(auth()->user(), 'dpdp.retention_rules.draft'))
                            <form method="post" action="{{ route('staff.privacy.retention.batch', $batch->id) }}">@csrf<button class="btn btn-outline-primary btn-sm" name="action" value="propose" type="submit">Propose disposal</button></form>
                        @endif
                        @if ($batch->status === 'proposed' && $batch->proposed_by !== auth()->id() && \App\Support\AccessContext::staffCan(auth()->user(), 'dpdp.retention_rules.record_approval'))
                            <form method="post" action="{{ route('staff.privacy.retention.batch', $batch->id) }}">@csrf<button class="btn btn-danger btn-sm" name="action" value="approve" type="submit">Approve and dispose</button></form>
                        @endif
                        @if ($batch->status === 'proposed' && \App\Support\AccessContext::staffCan(auth()->user(), 'dpdp.retention_rules.draft'))
                            <form method="post" action="{{ route('staff.privacy.retention.batch', $batch->id) }}">@csrf<button class="btn btn-link btn-sm" name="action" value="cancel" type="submit">Cancel</button></form>
                        @endif
                    </div>
                </div>
            @empty
                <p class="small text-body-secondary mb-0">Nothing is due. With no approved rule for a supported category, nothing ever is.</p>
            @endforelse
        </div></div></div>
        @if (\App\Support\AccessContext::staffCan(auth()->user(), 'dpdp.retention_rules.draft'))
            <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Draft a rule</h2>
                <form method="post" action="{{ route('staff.privacy.retention.store') }}">@csrf
                    <input class="form-control form-control-sm mb-2" name="data_category" placeholder="data_category" required aria-label="Data category">
                    <input class="form-control form-control-sm mb-2" name="retention_months" type="number" placeholder="Months to keep" aria-label="Months to keep">
                    <div class="form-text mb-2">Categories that can be enforced: @foreach (\App\Services\RetentionService::CATEGORIES as $code => $spec)<code>{{ $code }}</code> ({{ $spec['label'] }}, counted from tenant closure, {{ $spec['action'] }} on expiry)@if (! $loop->last); @endif @endforeach. Other categories are recorded but not enforced.</div>
                    <select class="form-select form-select-sm mb-2" name="trigger_event" aria-label="Counted from">@foreach (['account_closure', 'matter_closure', 'financial_year_end', 'creation', 'settlement_execution', 'request_closure', 'tenant_closure'] as $o)<option value="{{ $o }}">{{ ucfirst(str_replace('_', ' ', $o)) }}</option>@endforeach</select>
                    <select class="form-select form-select-sm mb-2" name="on_expiry" aria-label="When it expires">@foreach (['review', 'delete', 'anonymize', 'archive'] as $o)<option value="{{ $o }}">{{ ucfirst($o) }} on expiry</option>@endforeach</select>
                    <select class="form-select form-select-sm mb-2" name="on_erasure_request" aria-label="On an erasure request">@foreach (['retain', 'erase', 'anonymize'] as $o)<option value="{{ $o }}">{{ ucfirst($o) }} on erasure request</option>@endforeach</select>
                    <textarea class="form-control form-control-sm mb-2" name="legal_basis_note" rows="2" placeholder="Legal basis" required aria-label="Legal basis"></textarea>
                    <button class="btn btn-primary btn-sm" type="submit">Save draft</button>
                </form>
            </div></div></div>
        @endif
    </div>
@endsection
