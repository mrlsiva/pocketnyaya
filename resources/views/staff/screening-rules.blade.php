@extends('layouts.app')
@section('title', 'Screening rules')

@section('content')
    <h1 class="h4 mb-3">Screening rules</h1>
    <div class="row g-3">
        <div class="col-lg-8"><div class="card shadow-sm"><div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Pattern</th><th>Category</th><th>Severity</th><th>Applies to</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rules as $rule)
                        <tr class="{{ $rule->is_active ? '' : 'text-body-secondary' }}">
                            <td class="text-break"><code>{{ $rule->pattern }}</code></td>
                            <td>{{ str_replace('_', ' ', $rule->category) }}</td>
                            <td>{{ $rule->severity }}</td>
                            <td>{{ $rule->applies_to }}</td>
                            <td class="text-end"><form method="post" action="{{ route('staff.moderation.rules.toggle', $rule) }}">@csrf<button class="btn btn-link btn-sm p-0" type="submit">{{ $rule->is_active ? 'Turn off' : 'Turn on' }}</button></form></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-body-secondary">No rules yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div></div>
        <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Add a rule</h2>
            <form method="post" action="{{ route('staff.moderation.rules.store') }}">@csrf
                <select class="form-select form-select-sm mb-2" name="rule_type" aria-label="Rule type"><option value="keyword">Keyword</option><option value="regex">Pattern</option><option value="contact_pattern">Contact pattern</option></select>
                <input class="form-control form-control-sm mb-2" name="pattern" placeholder="Word or pattern" required aria-label="Pattern">
                <select class="form-select form-select-sm mb-2" name="category" aria-label="Category">@foreach (['laudatory', 'solicitation', 'fee_quote', 'contact_info', 'abusive', 'hostile_tone'] as $c)<option value="{{ $c }}">{{ ucfirst(str_replace('_', ' ', $c)) }}</option>@endforeach</select>
                <select class="form-select form-select-sm mb-2" name="severity" aria-label="Severity"><option value="flag">Send to moderator</option><option value="block">Block</option><option value="warn">Record only</option></select>
                <select class="form-select form-select-sm mb-2" name="applies_to" aria-label="Applies to"><option value="all">Everything</option><option value="profile">Profiles</option><option value="forum">Forum</option><option value="odr">ODR</option></select>
                <button class="btn btn-primary btn-sm" type="submit">Add rule</button>
            </form>
        </div></div></div>
    </div>
@endsection
