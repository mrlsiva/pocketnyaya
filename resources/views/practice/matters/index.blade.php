@extends('layouts.app')
@section('title', 'Matters')

@section('content')
    <h1 class="h4 mb-3">Matters</h1>
    @include('practice.nav')
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <form class="d-flex gap-2" method="get">
            <select class="form-select" name="status" aria-label="Filter by status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach (['unallocated', 'open', 'on_hold', 'disposed', 'closed', 'archived'] as $option)
                    <option value="{{ $option }}" @selected($status === $option)>{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
                @endforeach
            </select>
        </form>
        @can('create', \App\Models\Matter::class)
            <a class="btn btn-primary" href="{{ route('practice.matters.create') }}">New matter</a>
        @endcan
    </div>
    <div class="card shadow-sm"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Number</th><th>Title</th><th>Client</th><th>Status</th><th>Priority</th></tr></thead>
            <tbody>
                @forelse ($matters as $matter)
                    <tr>
                        <td class="text-nowrap"><a href="{{ route('practice.matters.show', $matter) }}">{{ $matter->matter_number }}</a></td>
                        <td>{{ $matter->title }}</td>
                        <td>{{ $matter->client?->name }}</td>
                        <td class="text-capitalize">{{ str_replace('_', ' ', $matter->status) }}</td>
                        <td class="text-capitalize">{{ $matter->priority }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-body-secondary">No matters to show.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>
    <div class="mt-3">{{ $matters->links('pagination::bootstrap-5') }}</div>
@endsection
