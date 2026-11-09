@extends('layouts.app')
@section('title', 'Data breaches')

@section('content')
    <h1 class="h4 mb-3">Data breach incidents</h1>
    @include('staff.privacy.nav')
    <div class="row g-3">
        <div class="col-lg-8">
            @forelse ($incidents as $incident)
                <div class="card shadow-sm mb-2"><div class="card-body">
                    <div class="small text-body-secondary text-capitalize">{{ $incident->severity }} · detected {{ $incident->detected_at->format('d M Y H:i') }}</div>
                    <p class="mb-2">{{ $incident->summary }}</p>
                    <form class="row g-1" method="post" action="{{ route('staff.privacy.breaches.update', $incident) }}">@csrf @method('put')
                        <div class="col-sm-3"><select class="form-select form-select-sm" name="status" aria-label="Status">@foreach (['open', 'contained', 'reported', 'closed'] as $o)<option value="{{ $o }}" @selected($incident->status === $o)>{{ ucfirst($o) }}</option>@endforeach</select></div>
                        <div class="col-sm-3"><input class="form-control form-control-sm" type="date" name="board_notified_at" value="{{ $incident->board_notified_at?->toDateString() }}" aria-label="Board notified on" title="Board notified on"></div>
                        <div class="col-sm-3"><input class="form-control form-control-sm" type="date" name="principals_notified_at" value="{{ $incident->principals_notified_at?->toDateString() }}" aria-label="People notified on" title="People notified on"></div>
                        <div class="col-sm-3"><button class="btn btn-outline-primary btn-sm" type="submit">Update</button></div>
                    </form>
                </div></div>
            @empty
                <p class="text-body-secondary">No incidents recorded.</p>
            @endforelse
        </div>
        <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Record an incident</h2>
            <form method="post" action="{{ route('staff.privacy.breaches.store') }}">@csrf
                <input class="form-control form-control-sm mb-2" name="detected_at" type="datetime-local" required aria-label="Detected at">
                <textarea class="form-control form-control-sm mb-2" name="summary" rows="3" placeholder="What happened" required aria-label="Summary"></textarea>
                <select class="form-select form-select-sm mb-2" name="severity" aria-label="Severity">@foreach (['low', 'medium', 'high', 'critical'] as $o)<option value="{{ $o }}">{{ ucfirst($o) }}</option>@endforeach</select>
                <input class="form-control form-control-sm mb-2" name="affected_principals" type="number" min="0" placeholder="People affected (if known)" aria-label="People affected">
                <button class="btn btn-primary btn-sm" type="submit">Record</button>
            </form>
        </div></div></div>
    </div>
@endsection
