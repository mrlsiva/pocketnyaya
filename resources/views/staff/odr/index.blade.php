@extends('layouts.app')
@section('title', 'ODR intake')

@section('content')
    <h1 class="h4 mb-1">ODR intake</h1>
    <p class="small text-body-secondary">You can see intake details and screening only. Messages, offers and evidence aren't available to staff.</p>
    <h2 class="h6">Needs a screening review</h2>
    @forelse ($screening as $dispute)
        <div class="card shadow-sm mb-2"><div class="card-body">
            <div class="fw-semibold">{{ $dispute->title }}</div>
            <div class="small text-body-secondary">{{ $dispute->dispute_number }} · {{ str_replace('_', ' ', $dispute->category) }}</div>
            <p class="small mt-1 mb-1" style="white-space: pre-line">{{ $dispute->description }}</p>
            @foreach ($answers[$dispute->id] ?? [] as $record)
                <div class="small">Screening result: {{ str_replace('_', ' ', $record->result) }}. {{ implode(', ', array_map(fn ($r) => str_replace('_', ' ', $r), (array) json_decode((string) $record->reasons))) }}</div>
            @endforeach
            <form class="d-flex flex-wrap gap-2 mt-2" method="post" action="{{ route('staff.odr.review', $dispute) }}">@csrf
                <input class="form-control form-control-sm w-auto flex-grow-1" name="note" placeholder="Reason" required aria-label="Reason">
                <button class="btn btn-primary btn-sm" name="result" value="suitable" type="submit">Suitable</button>
                <button class="btn btn-outline-danger btn-sm" name="result" value="unsuitable" type="submit">Not suitable</button>
            </form>
        </div></div>
    @empty
        <p class="text-body-secondary small">Nothing to review.</p>
    @endforelse

    <h2 class="h6 mt-4">Needs a mediator</h2>
    @forelse ($needingMediator as $dispute)
        <div class="card shadow-sm mb-2"><div class="card-body d-flex flex-wrap justify-content-between gap-2">
            <div>{{ $dispute->dispute_number }}<div class="small text-body-secondary">{{ str_replace('_', ' ', $dispute->category) }}</div></div>
            <form class="d-flex gap-2" method="post" action="{{ route('staff.odr.assign', $dispute) }}">@csrf
                <select class="form-select form-select-sm" name="mediator_profile_id" required aria-label="Mediator"><option value="">Choose…</option>
                    @foreach ($mediators as $mediator)<option value="{{ $mediator->id }}">{{ $mediator->user?->name }}</option>@endforeach
                </select>
                <button class="btn btn-primary btn-sm" type="submit">Propose</button>
            </form>
        </div></div>
    @empty
        <p class="text-body-secondary small">Nothing waiting.</p>
    @endforelse
@endsection
