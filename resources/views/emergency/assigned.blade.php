@extends('layouts.app')
@section('title', 'Assigned urgent requests')

@section('content')
    <h1 class="h4 mb-3">Urgent requests assigned to me</h1>
    @forelse ($requests as $request)
        <div class="card shadow-sm mb-2"><div class="card-body">
            <div class="fw-semibold text-capitalize">{{ str_replace('_', ' ', $request->category) }} · {{ $request->city?->name }}</div>
            <div class="small">Contact: {{ $request->contact_phone }}</div>
            <div style="white-space: pre-line">{{ $request->description }}</div>
        </div></div>
    @empty
        <p class="text-body-secondary">Nothing assigned to you right now.</p>
    @endforelse
@endsection
