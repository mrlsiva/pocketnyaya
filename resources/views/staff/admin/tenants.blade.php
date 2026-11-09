@extends('layouts.app')
@section('title', 'Practices')

@section('content')
    <h1 class="h4 mb-1">Practices</h1>
    <p class="small text-body-secondary">Names and status only. Staff can't open a practice's clients, matters or documents.</p>
    <form class="mb-3 d-flex gap-2" method="get"><input class="form-control form-control-sm w-auto" name="q" value="{{ $search }}" placeholder="Search" aria-label="Search"><button class="btn btn-outline-secondary btn-sm" type="submit">Search</button></form>
    <div class="card shadow-sm"><div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Name</th><th>Type</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse ($tenants as $tenant)
                    <tr>
                        <td>{{ $tenant->name }}</td><td class="text-capitalize">{{ $tenant->type }}</td><td class="text-capitalize">{{ $tenant->status }}</td>
                        <td>
                            @if ($tenant->status !== 'closed' && \App\Support\AccessContext::staffCan(auth()->user(), 'platform.tenants.suspend'))
                                <form class="d-flex gap-1" method="post" action="{{ route('staff.admin.tenants.update', $tenant) }}">@csrf @method('put')
                                    <input class="form-control form-control-sm" name="reason" placeholder="Reason" required aria-label="Reason">
                                    <button class="btn btn-outline-secondary btn-sm" name="status" value="{{ $tenant->status === 'active' ? 'suspended' : 'active' }}" type="submit">{{ $tenant->status === 'active' ? 'Suspend' : 'Reactivate' }}</button>
                                </form>
                            @endif
                            @if ($tenant->status !== 'closed' && \App\Support\AccessContext::staffCan(auth()->user(), 'platform.tenants.close'))
                                <a class="small" href="{{ route('staff.admin.offboarding.create', $tenant) }}">Close practice…</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-body-secondary">None found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>
    <div class="mt-3">{{ $tenants->links('pagination::bootstrap-5') }}</div>
@endsection
