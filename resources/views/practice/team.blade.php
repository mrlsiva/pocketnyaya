@extends('layouts.app')
@section('title', 'Team')

@section('content')
    <h1 class="h4 mb-3">Team</h1>
    @include('practice.nav')
    <div class="row g-3">
        <div class="col-lg-8"><div class="card shadow-sm"><div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Name</th><th>Role</th><th></th></tr></thead>
                <tbody>
                    @foreach ($members as $member)
                        <tr>
                            <td>{{ $member->name }}<div class="small text-body-secondary">{{ $member->email }}</div></td>
                            <td>{{ $member->roles }}</td>
                            <td class="text-end">
                                @if ($member->user_id !== auth()->id())
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        @if ($grantable)
                                            <form class="d-flex gap-1" method="post" action="{{ route('practice.team.role', $member->user_id) }}">@csrf @method('put')
                                                <select class="form-select form-select-sm w-auto" name="role" aria-label="Add role">
                                                    @foreach ($grantable as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach
                                                </select>
                                                <button class="btn btn-outline-secondary btn-sm" type="submit">Add role</button>
                                            </form>
                                        @endif
                                        @if ($canUpdateMembers)
                                            <form class="d-flex gap-1" method="post" action="{{ route('practice.team.capacity', $member->user_id) }}">@csrf @method('put')
                                                <input class="form-control form-control-sm" style="width: 6rem" name="max_active_matters" value="{{ $member->max_active_matters }}" placeholder="No limit" title="Most open matters at once" aria-label="Most open matters at once">
                                                <button class="btn btn-outline-secondary btn-sm" type="submit">Set limit</button>
                                            </form>
                                        @endif
                                        @if ($canDeactivate)
                                            <form method="post" action="{{ route('practice.team.deactivate', $member->user_id) }}">@csrf @method('delete')
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Remove</button></form>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div></div></div>
        @if ($canInvite)
            <div class="col-lg-4">
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Invite someone</h2>
                    <form method="post" action="{{ route('practice.team.invite') }}">@csrf
                        <input class="form-control form-control-sm mb-2" name="email" type="email" placeholder="Email" required aria-label="Email">
                        <select class="form-select form-select-sm mb-2" name="role" aria-label="Role">
                            @foreach ($grantable as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach
                        </select>
                        <button class="btn btn-primary btn-sm" type="submit">Send invitation</button>
                    </form>
                </div></div>
                @if ($invitations->isNotEmpty())
                    <div class="card shadow-sm"><div class="card-body">
                        <h2 class="h6">Pending invitations</h2>
                        @foreach ($invitations as $invitation)
                            <div class="d-flex justify-content-between small py-1">
                                <span>{{ $invitation->email }} ({{ $invitation->role_name }})</span>
                                <form method="post" action="{{ route('practice.team.invitations.revoke', $invitation->id) }}">@csrf @method('delete')<button class="btn btn-link btn-sm p-0" type="submit">Revoke</button></form>
                            </div>
                        @endforeach
                    </div></div>
                @endif
            </div>
        @endif
    </div>
@endsection
