@extends('layouts.app')
@section('title', 'Urgent requests')

@section('content')
    <h1 class="h4 mb-3">Urgent requests</h1>
    @forelse ($requests as $request)
        <div class="card shadow-sm mb-2"><div class="card-body">
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <div>
                    <span class="badge text-bg-danger text-uppercase">{{ $request->priority }}</span>
                    <span class="fw-semibold text-capitalize">{{ str_replace('_', ' ', $request->category) }}</span> · {{ $request->city?->name }}
                    <div class="small text-body-secondary">Respond by {{ $request->sla_due_at->format('d M H:i') }} · <span class="text-capitalize">{{ $request->status }}</span></div>
                    <div class="small">Contact: {{ $request->contact_phone }}</div>
                    <div style="white-space: pre-line">{{ $request->description }}</div>
                </div>
                <form class="d-flex flex-wrap gap-2 align-items-start" method="post" action="{{ route('staff.emergency.triage', $request) }}">@csrf
                    <select class="form-select form-select-sm w-auto" name="status" aria-label="Status">
                        @foreach (['acknowledged', 'assigned', 'referred', 'resolved', 'closed'] as $status)<option value="{{ $status }}">{{ ucfirst($status) }}</option>@endforeach
                    </select>
                    <select class="form-select form-select-sm w-auto" name="assigned_responder_id" aria-label="Responder"><option value="">No responder</option>
                        @foreach ($responders as $responder)<option value="{{ $responder->id }}">{{ $responder->advocateProfile?->user?->name ?? $responder->organization_name }}</option>@endforeach
                    </select>
                    <button class="btn btn-primary btn-sm" type="submit">Update</button>
                </form>
            </div>
        </div></div>
    @empty
        <p class="text-body-secondary">No open requests.</p>
    @endforelse
    <div class="mt-3">{{ $requests->links('pagination::bootstrap-5') }}</div>
@endsection
