@extends('layouts.app')
@section('title', 'Practice closures')

@section('content')
    <h1 class="h4 mb-3">Practice closures</h1>
    <div class="card shadow-sm"><div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Practice</th><th>Stage</th><th>Requested</th></tr></thead>
            <tbody>
                @forelse ($closures as $closure)
                    <tr>
                        <td><a href="{{ route('staff.admin.offboarding.show', $closure) }}">{{ $closure->tenant?->name }}</a></td>
                        <td class="text-capitalize">{{ str_replace('_', ' ', $closure->status) }}</td>
                        <td>{{ $closure->requested_at?->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-body-secondary">No closures. Start one from the Practices list.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>
@endsection
