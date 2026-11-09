@extends('layouts.app')
@section('title', $title)

@section('content')
    <h1 class="h4 mb-3">{{ $title }}</h1>
    <form class="row g-2 mb-3" method="get">
        <div class="col-auto"><input class="form-control form-control-sm" type="date" name="from" value="{{ $from }}" aria-label="From"></div>
        <div class="col-auto"><input class="form-control form-control-sm" type="date" name="to" value="{{ $to }}" aria-label="To"></div>
        <div class="col-auto"><input class="form-control form-control-sm" name="event" value="{{ $event }}" placeholder="Event" aria-label="Event"></div>
        <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button></div>
    </form>
    <div class="card shadow-sm"><div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>When (UTC)</th><th>Who</th><th>Event</th><th>Record</th><th>Channel</th></tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($log->created_at)->format('d M Y H:i:s') }}</td>
                        <td>{{ $log->actor ?? 'System' }}</td>
                        <td>{{ $log->event }}</td>
                        <td class="text-break small">{{ $log->auditable_type }} {{ $log->auditable_id }}</td>
                        <td>{{ $log->channel }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-body-secondary">Nothing in this date range.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>
    <div class="d-flex gap-2 mt-3">
        @if ($logs->previousPageUrl())<a class="btn btn-outline-secondary btn-sm" href="{{ $logs->previousPageUrl() }}">Newer</a>@endif
        @if ($logs->nextPageUrl())<a class="btn btn-outline-secondary btn-sm" href="{{ $logs->nextPageUrl() }}">Older</a>@endif
    </div>
@endsection
