@extends('layouts.app')
@section('title', 'Verification queue')

@section('content')
    <h1 class="h4 mb-3">Verification queue</h1>
    <div class="card shadow-sm"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Applicant</th><th>Type</th><th>Status</th><th>Submitted</th></tr></thead>
            <tbody>
                @forelse ($requests as $request)
                    <tr>
                        <td><a href="{{ route('staff.verification.show', $request) }}">{{ $request->advocateProfile?->user?->name ?? $request->mediatorProfile?->user?->name }}</a></td>
                        <td>{{ $request->mediator_profile_id ? 'Mediator' : 'Advocate' }}</td>
                        <td class="text-capitalize">{{ str_replace('_', ' ', $request->status) }}</td>
                        <td class="text-nowrap">{{ $request->submitted_at->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-body-secondary">The queue is empty.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>
    <div class="mt-3">{{ $requests->links('pagination::bootstrap-5') }}</div>
@endsection
