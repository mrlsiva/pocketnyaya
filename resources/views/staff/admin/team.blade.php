@extends('layouts.app')
@section('title', 'Platform staff')

@section('content')
    <h1 class="h4 mb-3">Platform staff</h1>
    <p class="small text-body-secondary">Active Super Admins: {{ $superAdmins }}. At least {{ $minimum }} are needed at all times. A new Super Admin needs a proposal and the approval of a different Super Admin. No one can change their own staff roles.</p>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm mb-3"><div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Name</th><th>Role</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($staff as $person)
                            <tr>
                                <td>{{ $person->name }}<div class="small text-body-secondary text-break">{{ $person->email }} · {{ $person->status }}</div></td>
                                <td>{{ $person->role }}</td>
                                <td class="text-end">
                                    @if ($person->id !== $me && $person->role === 'Compliance Officer' && $canAssign)
                                        <form method="post" action="{{ route('staff.admin.team.compliance') }}">@csrf
                                            <input type="hidden" name="email" value="{{ $person->email }}"><input type="hidden" name="action" value="revoke">
                                            <button class="btn btn-link btn-sm p-0" type="submit">Remove role</button>
                                        </form>
                                    @elseif ($person->id !== $me && $person->role === 'Super Admin' && $canApprove && $superAdmins > $minimum)
                                        <form method="post" action="{{ route('staff.admin.team.revoke', $person->id) }}">@csrf
                                            <button class="btn btn-link btn-sm p-0" type="submit">Remove role</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-body-secondary">No staff yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div></div>

            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Open Super Admin proposals</h2>
                @forelse ($proposals as $proposal)
                    <div class="d-flex flex-wrap justify-content-between gap-2 py-2 border-bottom small">
                        <div>{{ $proposal->user->name }} <span class="text-body-secondary text-break">{{ $proposal->user->email }}</span>
                            <div class="text-body-secondary">Proposed by {{ $proposal->proposer_name }} on {{ \Illuminate\Support\Carbon::parse($proposal->proposed_at)->format('d M Y') }}</div></div>
                        <div class="d-flex gap-2">
                            @if ($canApprove && $proposal->proposed_by !== $me && $proposal->user->id !== $me)
                                <form method="post" action="{{ route('staff.admin.team.decide', $proposal->user->id) }}">@csrf<button class="btn btn-primary btn-sm" name="decision" value="approve" type="submit">Approve</button></form>
                            @elseif ($proposal->proposed_by === $me)
                                <span class="text-body-secondary">Waiting for another Super Admin</span>
                            @endif
                            @if ($canPropose)
                                <form method="post" action="{{ route('staff.admin.team.decide', $proposal->user->id) }}">@csrf<button class="btn btn-outline-secondary btn-sm" name="decision" value="withdraw" type="submit">Withdraw</button></form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="small text-body-secondary mb-0">No open proposals.</p>
                @endforelse
            </div></div>
        </div>

        <div class="col-lg-5">
            @if ($canPropose)
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Propose a Super Admin</h2>
                    <form method="post" action="{{ route('staff.admin.team.propose') }}">@csrf
                        <input class="form-control form-control-sm mb-2" name="email" type="email" placeholder="Their account email" required aria-label="Email">
                        <button class="btn btn-primary btn-sm" type="submit">Propose</button>
                    </form>
                </div></div>
            @endif
            @if ($canAssign)
                <div class="card shadow-sm"><div class="card-body">
                    <h2 class="h6">Add a Compliance Officer</h2>
                    <form method="post" action="{{ route('staff.admin.team.compliance') }}">@csrf
                        <input type="hidden" name="action" value="grant">
                        <input class="form-control form-control-sm mb-2" name="email" type="email" placeholder="Their account email" required aria-label="Email">
                        <button class="btn btn-primary btn-sm" type="submit">Grant role</button>
                    </form>
                    <p class="small text-body-secondary mt-2 mb-0">The account must be active with a verified email. They will set up an authenticator app when they first open the staff area.</p>
                </div></div>
            @endif
        </div>
    </div>
@endsection
