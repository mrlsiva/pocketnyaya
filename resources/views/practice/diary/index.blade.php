@extends('layouts.app')
@section('title', 'My diary')

@section('content')
    <h1 class="h4 mb-3">My diary</h1>
    @include('practice.nav')

    @if ($conflicts->isNotEmpty())
        <div class="card border-warning shadow-sm mb-3"><div class="card-body">
            <h2 class="h6">Schedule conflicts</h2>
            @foreach ($conflicts as $conflict)
                <div class="d-flex flex-wrap justify-content-between gap-2 border-bottom py-2">
                    <span>{{ $conflict->hearingA?->hearing_date?->format('d M Y') }}: {{ $conflict->hearingA?->court?->name }} and {{ $conflict->hearingB?->court?->name }}
                        <span class="small text-body-secondary">{{ str_replace('_', ' ', $conflict->conflict_type) }}</span></span>
                    <form method="post" action="{{ route('practice.conflicts.resolve', $conflict) }}">@csrf<button class="btn btn-outline-secondary btn-sm" type="submit">Mark resolved</button></form>
                </div>
            @endforeach
        </div></div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">My hearings from {{ $from->format('d M Y') }} (14 days)</h2>
                <ul class="list-group list-group-flush">
                    @forelse ($hearings as $hearing)
                        <li class="list-group-item px-0">
                            <div class="fw-semibold">{{ $hearing->hearing_date->format('D, d M') }} {{ $hearing->scheduled_time ? substr($hearing->scheduled_time, 0, 5) : '' }}</div>
                            <a href="{{ route('practice.matters.show', $hearing->matter) }}">{{ $hearing->matter->matter_number }}: {{ $hearing->matter->title }}</a>
                            <div class="small text-body-secondary">{{ $hearing->court?->name }} {{ $hearing->purpose ? "· {$hearing->purpose}" : '' }}</div>
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-body-secondary">No hearings in this period.</li>
                    @endforelse
                </ul>
            </div></div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">My entries</h2>
                <ul class="list-group list-group-flush mb-3">
                    @forelse ($entries as $entry)
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span>{{ $entry->starts_at->format('d M H:i') }}: {{ $entry->title }}</span>
                            <form method="post" action="{{ route('practice.diary.destroy', $entry) }}">@csrf @method('delete')<button class="btn btn-link btn-sm p-0" type="submit">Remove</button></form>
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-body-secondary">Nothing yet.</li>
                    @endforelse
                </ul>
                @if ($canManageEntries)
                    <form class="row g-2" method="post" action="{{ route('practice.diary.store') }}">@csrf
                        <div class="col-12"><input class="form-control form-control-sm" name="title" placeholder="Title" required></div>
                        <div class="col-7"><input class="form-control form-control-sm" name="starts_at" type="datetime-local" required aria-label="Starts at"></div>
                        <div class="col-5"><select class="form-select form-select-sm" name="entry_type" aria-label="Entry type">
                            <option value="meeting">Meeting</option><option value="reminder">Reminder</option><option value="filing_deadline">Filing deadline</option><option value="personal">Personal</option>
                        </select></div>
                        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Add entry</button></div>
                    </form>
                @endif
            </div></div>
        </div>
    </div>
@endsection
