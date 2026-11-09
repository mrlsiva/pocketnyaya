@extends('layouts.app')
@section('title', $heading)

@section('content')
    <h1 class="h4 mb-3">{{ $heading }}</h1>
    @if ($nav) @include($nav) @endif
    <div class="card shadow-sm"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Subject</th><th>With</th><th>Status</th><th>Received</th></tr></thead>
            <tbody>
                @forelse ($consultations as $consultation)
                    <tr>
                        <td><a href="{{ route($showRoute, $consultation) }}">{{ $consultation->subject }}</a></td>
                        <td>{{ $consultation->customer?->name ?? $consultation->advocateProfile?->user?->name }}</td>
                        <td class="text-capitalize">{{ $consultation->status }}</td>
                        <td class="text-nowrap">{{ $consultation->created_at->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-body-secondary">Nothing here yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>
    <div class="mt-3">{{ $consultations->links('pagination::bootstrap-5') }}</div>
@endsection
