@extends('layouts.app')
@section('title', $consultation->subject)

@section('content')
    <h1 class="h4 mb-1">{{ $consultation->subject }}</h1>
    <div class="small text-body-secondary mb-3 text-capitalize">{{ $consultation->status }} · {{ str_replace('_', ' ', $consultation->mode) }}
        @if ($consultation->scheduled_at) · {{ $consultation->scheduled_at->format('d M Y H:i') }}@endif</div>
    @if ($nav) @include($nav) @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Request</h2>
                <p class="mb-0" style="white-space: pre-line">{{ $consultation->description }}</p>
            </div></div>
            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Messages</h2>
                @forelse ($messages as $message)
                    <div class="border-bottom py-2">
                        <div class="small text-body-secondary">{{ $message->sender?->name }} · {{ $message->created_at?->format('d M Y H:i') }}</div>
                        <div style="white-space: pre-line">{{ $message->body }}</div>
                    </div>
                @empty
                    <p class="small text-body-secondary">No messages yet.</p>
                @endforelse
                @if ($consultation->acceptsMessages())
                    <form class="mt-3" method="post" action="{{ $messageRoute }}">@csrf
                        <textarea class="form-control mb-2" name="body" rows="2" required aria-label="New message"></textarea>
                        <button class="btn btn-primary btn-sm" type="submit">Send</button>
                    </form>
                @endif
            </div></div>
        </div>
        <div class="col-lg-4">
            @if ($isPractice)
                @if ($canRespond && isset(\App\Models\ConsultationRequest::TRANSITIONS[$consultation->status]))
                    <div class="card shadow-sm mb-3"><div class="card-body">
                        <h2 class="h6">Respond</h2>
                        <form method="post" action="{{ route('practice.consultations.respond', $consultation) }}">@csrf
                            <select class="form-select form-select-sm mb-2" name="status" aria-label="New status">
                                @foreach (\App\Models\ConsultationRequest::TRANSITIONS[$consultation->status] as $next)<option value="{{ $next }}">{{ ucfirst($next) }}</option>@endforeach
                            </select>
                            <input class="form-control form-control-sm mb-2" name="scheduled_at" type="datetime-local" aria-label="Scheduled time">
                            <button class="btn btn-primary btn-sm" type="submit">Update</button>
                        </form>
                    </div></div>
                @endif
                @if ($canConvert)
                    <form class="mb-3" method="post" action="{{ route('practice.consultations.convert', $consultation) }}">@csrf
                        <button class="btn btn-outline-primary btn-sm w-100" type="submit">Create a matter from this</button></form>
                @endif
                @if ($canNotes)
                    <div class="card shadow-sm"><div class="card-body">
                        <h2 class="h6">My private notes</h2>
                        <form method="post" action="{{ route('practice.consultations.notes', $consultation) }}">@csrf @method('put')
                            <textarea class="form-control form-control-sm mb-2" name="lawyer_private_notes" rows="4" aria-label="Private notes">{{ $consultation->lawyer_private_notes }}</textarea>
                            <button class="btn btn-primary btn-sm" type="submit">Save</button>
                        </form>
                        <p class="small text-body-secondary mt-2 mb-0">Only you can see these.</p>
                    </div></div>
                @endif
            @elseif (in_array($consultation->status, ['requested', 'accepted', 'scheduled'], true))
                <form method="post" action="{{ route('citizen.consultations.cancel', $consultation) }}">@csrf
                    <button class="btn btn-outline-danger btn-sm" type="submit">Cancel this request</button></form>
            @endif
        </div>
    </div>
@endsection
