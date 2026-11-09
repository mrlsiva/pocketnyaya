@extends('layouts.app')
@section('title', 'Clients')

@section('content')
    <h1 class="h4 mb-3">Clients</h1>
    @include('practice.nav')
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <form class="d-flex gap-2" method="get">
            <input class="form-control" name="q" value="{{ $search }}" placeholder="Name or exact phone" aria-label="Search clients">
            <button class="btn btn-outline-secondary" type="submit">Search</button>
        </form>
        @can('create', \App\Models\Client::class)
            <a class="btn btn-primary" href="{{ route('practice.clients.create') }}">Add client</a>
        @endcan
    </div>
    <div class="card shadow-sm"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Name</th><th>Type</th><th>Email</th></tr></thead>
            <tbody>
                @forelse ($clients as $client)
                    <tr>
                        <td><a href="{{ route('practice.clients.show', $client) }}">{{ $client->name }}</a></td>
                        <td class="text-capitalize">{{ $client->client_type }}</td>
                        <td>{{ $client->email }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-body-secondary">No clients yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>
    <div class="mt-3">{{ $clients->links('pagination::bootstrap-5') }}</div>
@endsection
