@extends('layouts.app')
@section('title', 'Request a consultation')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-7">
        <div class="card shadow-sm"><div class="card-body p-4">
            <h1 class="h4">Request a consultation with {{ $advocate->user?->name }}</h1>
            <form method="post" action="{{ route('citizen.consultations.store', $advocate->id) }}">@csrf
                <div class="mb-3">
                    <label class="form-label" for="mode">How would you like to talk?</label>
                    <select class="form-select" id="mode" name="mode">
                        <option value="chat">Chat</option><option value="call">Phone call</option><option value="video">Video</option><option value="in_person">In person</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="subject">Subject</label>
                    <input class="form-control" id="subject" name="subject" value="{{ old('subject') }}" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="description">Describe your matter</label>
                    <textarea class="form-control" id="description" name="description" rows="5" required>{{ old('description') }}</textarea>
                </div>
                <button class="btn btn-primary" type="submit">Send request</button>
            </form>
        </div></div>
    </div></div>
@endsection
