@extends('layouts.app')
@section('title', 'Online dispute resolution')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <h1 class="h4 mb-0">Online dispute resolution</h1>
        @if ($canCreate)<a class="btn btn-primary btn-sm" href="{{ route('citizen.odr.create') }}">Start a dispute</a>@endif
    </div>
    @foreach ($proposed as $assignment)
        <div class="card shadow-sm border-primary mb-3"><div class="card-body">
            <h2 class="h6">You've been proposed as mediator</h2>
            <p class="small mb-2">{{ $assignment->dispute?->dispute_number }}. Category: {{ str_replace('_', ' ', (string) $assignment->dispute?->category) }}.</p>
            <form method="post" action="{{ route('citizen.odr.assignments.respond', $assignment) }}">@csrf
                <textarea class="form-control form-control-sm mb-2" name="conflict_disclosure" rows="2" placeholder="Disclose any conflict of interest, or write None" required aria-label="Conflict disclosure"></textarea>
                <button class="btn btn-primary btn-sm" name="decision" value="accept" type="submit">Accept</button>
                <button class="btn btn-outline-secondary btn-sm" name="decision" value="decline" type="submit">Decline</button>
            </form>
        </div></div>
    @endforeach
    <div class="card shadow-sm"><div class="list-group list-group-flush">
        @forelse ($disputes as $dispute)
            <a class="list-group-item list-group-item-action d-flex flex-wrap justify-content-between gap-2" href="{{ route('citizen.odr.show', $dispute) }}">
                <span>{{ $dispute->title }}<span class="d-block small text-body-secondary">{{ $dispute->dispute_number }}</span></span>
                <span class="badge text-bg-light align-self-start text-capitalize">{{ str_replace('_', ' ', $dispute->status) }}</span>
            </a>
        @empty
            <div class="list-group-item text-body-secondary">You aren't part of any dispute.</div>
        @endforelse
    </div></div>
@endsection
