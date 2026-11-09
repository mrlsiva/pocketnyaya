@extends('layouts.app')
@section('title', $client->exists ? 'Edit client' : 'Add client')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-8">
        <div class="card shadow-sm"><div class="card-body p-4">
            <h1 class="h4 mb-3">{{ $client->exists ? 'Edit client' : 'Add client' }}</h1>
            <form method="post" action="{{ $client->exists ? route('practice.clients.update', $client) : route('practice.clients.store') }}">@csrf
                @if ($client->exists) @method('put') @endif
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" id="name" name="name" value="{{ old('name', $client->name) }}" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="client_type">Type</label>
                        <select class="form-select" id="client_type" name="client_type">
                            @foreach (['individual' => 'Individual', 'business' => 'Business', 'government' => 'Government'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('client_type', $client->client_type) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $client->email) }}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="phone">Phone</label>
                        <input class="form-control" id="phone" name="phone" type="tel" value="{{ old('phone', $client->phone) }}">
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label" for="address">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2">{{ old('address', $client->address) }}</textarea>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="state_code">State (place of supply)</label>
                        <select class="form-select" id="state_code" name="state_code" required>
                            <option value="">Choose…</option>
                            @foreach ($states as $state)
                                <option value="{{ $state->code }}" @selected(old('state_code', $client->state_code) === $state->code)>{{ $state->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="gstin">GSTIN</label>
                        <input class="form-control" id="gstin" name="gstin" value="{{ old('gstin', $client->gstin) }}" maxlength="15">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="pan">PAN</label>
                        <input class="form-control" id="pan" name="pan" value="{{ old('pan', $client->pan) }}" maxlength="10">
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3">{{ old('notes', $client->notes) }}</textarea>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">Save</button>
                <a class="btn btn-link" href="{{ $client->exists ? route('practice.clients.show', $client) : route('practice.clients.index') }}">Cancel</a>
            </form>
        </div></div>
    </div></div>
@endsection
