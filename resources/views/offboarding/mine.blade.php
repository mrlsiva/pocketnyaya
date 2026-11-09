@extends('layouts.app')
@section('title', 'Practice exports')

@section('content')
    <h1 class="h4 mb-3">Practice exports</h1>
    <p class="small text-body-secondary">When a practice you lead is closed, you can download its records and documents here. Only you can download them.</p>
    <div class="card shadow-sm"><div class="list-group list-group-flush">
        @forelse ($closures as $closure)
            <div class="list-group-item d-flex flex-wrap justify-content-between gap-2 align-items-center">
                <div>{{ $closure->tenant?->name }}
                    <div class="small text-body-secondary">
                        @if ($closure->exportAvailable()) Available until {{ $closure->export_expires_at->format('d M Y') }} @else No longer available @endif
                    </div>
                </div>
                @if ($closure->exportAvailable())
                    <a class="btn btn-primary btn-sm" href="{{ route('offboarding.download', $closure) }}">Download ZIP</a>
                @endif
            </div>
        @empty
            <div class="list-group-item text-body-secondary">There are no exports for you.</div>
        @endforelse
    </div></div>
    <p class="small text-body-secondary mt-3">The export contains confidential client information. Store it securely.</p>
@endsection
