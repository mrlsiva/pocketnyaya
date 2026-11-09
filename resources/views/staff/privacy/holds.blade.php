@extends('layouts.app')
@section('title', 'Legal holds')

@section('content')
    <h1 class="h4 mb-3">Legal holds</h1>
    @include('staff.privacy.nav')
    <div class="row g-3">
        <div class="col-lg-8"><div class="card shadow-sm"><div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Covers</th><th>Reason</th><th>Placed</th><th></th></tr></thead>
                <tbody>
                    @forelse ($holds as $hold)
                        <tr>
                            <td class="text-break"><span class="text-capitalize">{{ str_replace('_', ' ', $hold->holdable_type) }}</span><div class="small text-body-secondary">{{ $hold->holdable_id }}</div></td>
                            <td>{{ $hold->reason }}</td>
                            <td class="text-nowrap">{{ $hold->placed_at->format('d M Y') }}</td>
                            <td>
                                @if ($hold->released_at)
                                    <span class="small">Released {{ $hold->released_at->format('d M Y') }}</span>
                                @else
                                    <form method="post" action="{{ route('staff.privacy.holds.release', $hold) }}">@csrf<button class="btn btn-link btn-sm p-0" type="submit">Release</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-body-secondary">No holds.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div></div>
        <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Place a hold</h2>
            <form method="post" action="{{ route('staff.privacy.holds.store') }}">@csrf
                <select class="form-select form-select-sm mb-2" name="holdable_type" aria-label="What it covers">@foreach (['user', 'document', 'matter', 'tenant', 'odr_dispute'] as $o)<option value="{{ $o }}">{{ ucfirst(str_replace('_', ' ', $o)) }}</option>@endforeach</select>
                <input class="form-control form-control-sm mb-2" name="holdable_id" placeholder="Record ID" required aria-label="Record ID">
                <textarea class="form-control form-control-sm mb-2" name="reason" rows="2" placeholder="Reason" required aria-label="Reason"></textarea>
                <button class="btn btn-primary btn-sm" type="submit">Place hold</button>
            </form>
        </div></div></div>
    </div>
@endsection
