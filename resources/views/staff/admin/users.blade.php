@extends('layouts.app')
@section('title', 'Accounts')

@section('content')
    <h1 class="h4 mb-3">Accounts</h1>
    <form class="mb-3 d-flex gap-2" method="get"><input class="form-control form-control-sm w-auto" name="q" value="{{ $search }}" placeholder="Search" aria-label="Search"><button class="btn btn-outline-secondary btn-sm" type="submit">Search</button></form>
    <div class="card shadow-sm"><div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Name</th><th>Email</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse ($users as $person)
                    <tr>
                        <td>{{ $person->name }}</td><td class="text-break">{{ $person->email }}</td><td class="text-capitalize">{{ $person->status }}</td>
                        <td>
                            @if ($person->id !== auth()->id() && \App\Support\AccessContext::staffCan(auth()->user(), 'platform.users.suspend'))
                                <form class="d-flex gap-1" method="post" action="{{ route('staff.admin.users.update', $person) }}">@csrf @method('put')
                                    <input class="form-control form-control-sm" name="reason" placeholder="Reason" required aria-label="Reason">
                                    <button class="btn btn-outline-secondary btn-sm" name="status" value="{{ $person->status === 'suspended' ? 'active' : 'suspended' }}" type="submit">{{ $person->status === 'suspended' ? 'Reactivate' : 'Suspend' }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-body-secondary">None found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>
    <div class="mt-3">{{ $users->links('pagination::bootstrap-5') }}</div>
@endsection
