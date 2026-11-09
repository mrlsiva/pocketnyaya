@extends('layouts.app')
@section('title', 'Firm calendar')

@section('content')
    <h1 class="h4 mb-3">Firm calendar</h1>
    @include('practice.nav')
    <p class="small text-body-secondary">From {{ $from->format('d M Y') }}, 14 days. Matter details appear only for matters you can see.</p>
    <div class="card shadow-sm"><div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Date</th><th>Court</th><th>Matter</th><th>Attending</th></tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="text-nowrap">{{ $row['hearing']->hearing_date->format('D, d M') }} {{ $row['hearing']->scheduled_time ? substr($row['hearing']->scheduled_time, 0, 5) : '' }}</td>
                        <td>{{ $row['hearing']->court?->name }} {{ $row['hearing']->court_hall ? "· Hall {$row['hearing']->court_hall}" : '' }}</td>
                        <td>{{ $row['title'] }}</td>
                        <td>{{ $row['attendees'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-body-secondary">No hearings in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>
@endsection
