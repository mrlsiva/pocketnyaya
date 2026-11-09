@extends('layouts.app')
@section('title', $client->name)

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <h1 class="h4 mb-0">{{ $client->name }}</h1>
        <div class="d-flex gap-2">
            @can('update', $client)<a class="btn btn-outline-secondary btn-sm" href="{{ route('practice.clients.edit', $client) }}">Edit</a>@endcan
            @can('create', \App\Models\Matter::class)<a class="btn btn-primary btn-sm" href="{{ route('practice.matters.create', ['client' => $client->id]) }}">New matter</a>@endcan
        </div>
    </div>
    @include('practice.nav')
    <div class="row g-3">
        <div class="col-lg-5"><div class="card shadow-sm"><div class="card-body">
            <dl class="mb-0">
                <dt class="small text-body-secondary">Type</dt><dd class="text-capitalize">{{ $client->client_type }}</dd>
                <dt class="small text-body-secondary">Email</dt><dd>{{ $client->email ?: '—' }}</dd>
                <dt class="small text-body-secondary">Phone</dt><dd>{{ $client->phone ?: '—' }}</dd>
                <dt class="small text-body-secondary">Address</dt><dd>{{ $client->address ?: '—' }}</dd>
                <dt class="small text-body-secondary">GSTIN</dt><dd class="mb-0">{{ $client->gstin ?: '—' }}</dd>
            </dl>
        </div></div></div>
        <div class="col-lg-7"><div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Matters</h2>
            <ul class="list-group list-group-flush">
                @forelse ($matters as $matter)
                    <li class="list-group-item px-0"><a href="{{ route('practice.matters.show', $matter) }}">{{ $matter->matter_number }}: {{ $matter->title }}</a>
                        <span class="badge text-bg-light text-capitalize">{{ str_replace('_', ' ', $matter->status) }}</span></li>
                @empty
                    <li class="list-group-item px-0 text-body-secondary">No matters yet.</li>
                @endforelse
            </ul>
        </div></div></div>
    </div>
@endsection
