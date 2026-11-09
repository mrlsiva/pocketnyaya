@extends('layouts.app')
@section('title', 'Moderation queue')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <h1 class="h4 mb-0">Moderation queue</h1>
        @if (\App\Support\AccessContext::staffCan(auth()->user(), 'moderation.rules.manage'))
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('staff.moderation.rules') }}">Screening rules</a>
        @endif
    </div>
    <ul class="nav nav-tabs mb-3">
        @foreach (['pending', 'escalated', 'approved', 'rejected'] as $tab)
            <li class="nav-item"><a class="nav-link text-capitalize {{ $status === $tab ? 'active' : '' }}" href="{{ route('staff.moderation.index', ['status' => $tab]) }}">{{ $tab }}</a></li>
        @endforeach
    </ul>
    @forelse ($cases as $case)
        <div class="card shadow-sm mb-2"><div class="card-body">
            <div class="small text-body-secondary text-capitalize">{{ str_replace('_', ' ', $case->moderatable_type) }} · {{ $case->reason }} · {{ $case->created_at->format('d M Y') }}</div>
            <p class="mt-1 mb-2" style="white-space: pre-line">{{ $texts[$case->id] ?? 'This content is no longer available.' }}</p>
            @if ($canDecide && in_array($case->status, ['pending'], true))
                <form class="d-flex flex-wrap gap-2" method="post" action="{{ route('staff.moderation.decide', $case) }}">@csrf
                    <input class="form-control form-control-sm w-auto flex-grow-1" name="decision_note" placeholder="Note (optional)" aria-label="Note">
                    <button class="btn btn-primary btn-sm" name="decision" value="approved" type="submit">Approve</button>
                    <button class="btn btn-outline-danger btn-sm" name="decision" value="rejected" type="submit">Reject</button>
                    <button class="btn btn-outline-secondary btn-sm" name="decision" value="escalated" type="submit">Escalate</button>
                </form>
            @endif
        </div></div>
    @empty
        <p class="text-body-secondary">Nothing here.</p>
    @endforelse
    <div class="mt-3">{{ $cases->links('pagination::bootstrap-5') }}</div>
@endsection
