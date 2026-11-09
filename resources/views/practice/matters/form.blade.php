@extends('layouts.app')
@section('title', $matter->exists ? 'Edit matter' : 'New matter')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-8">
        <div class="card shadow-sm"><div class="card-body p-4">
            <h1 class="h4 mb-3">{{ $matter->exists ? 'Edit matter' : 'New matter' }}</h1>
            <form method="post" action="{{ $matter->exists ? route('practice.matters.update', $matter) : route('practice.matters.store') }}">@csrf
                @if ($matter->exists) @method('put') @endif
                <div class="row">
                    @unless ($matter->exists)
                        <div class="col-12 mb-3">
                            <label class="form-label" for="client_id">Client</label>
                            <select class="form-select" id="client_id" name="client_id" required>
                                <option value="">Choose…</option>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->id }}" @selected(old('client_id', $matter->client_id) === $client->id)>{{ $client->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endunless
                    <div class="col-12 mb-3">
                        <label class="form-label" for="title">Title</label>
                        <input class="form-control" id="title" name="title" value="{{ old('title', $matter->title) }}" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="matter_type">Type</label>
                        <select class="form-select" id="matter_type" name="matter_type">
                            @foreach (['litigation' => 'Litigation', 'advisory' => 'Advisory', 'drafting' => 'Drafting', 'creator_retainer' => 'Creator retainer', 'other' => 'Other'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('matter_type', $matter->matter_type) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="priority">Priority</label>
                        <select class="form-select" id="priority" name="priority">
                            @foreach (['low', 'normal', 'high', 'urgent'] as $value)
                                <option value="{{ $value }}" @selected(old('priority', $matter->priority) === $value)>{{ ucfirst($value) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="practice_area_id">Practice area</label>
                        <select class="form-select" id="practice_area_id" name="practice_area_id">
                            <option value="">None</option>
                            @foreach ($areas as $area)
                                <option value="{{ $area->id }}" @selected((int) old('practice_area_id', $matter->practice_area_id) === $area->id)>{{ $area->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="court_id">Court</label>
                        <select class="form-select" id="court_id" name="court_id">
                            <option value="">None</option>
                            @foreach ($courts as $court)
                                <option value="{{ $court->id }}" @selected((int) old('court_id', $matter->court_id) === $court->id)>{{ $court->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="case_number">Case number</label>
                        <input class="form-control" id="case_number" name="case_number" value="{{ old('case_number', $matter->case_number) }}">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="case_year">Case year</label>
                        <input class="form-control" id="case_year" name="case_year" type="number" value="{{ old('case_year', $matter->case_year) }}">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="cnr_number">CNR number</label>
                        <input class="form-control" id="cnr_number" name="cnr_number" value="{{ old('cnr_number', $matter->cnr_number) }}" maxlength="16">
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3">{{ old('description', $matter->description) }}</textarea>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">Save</button>
                <a class="btn btn-link" href="{{ $matter->exists ? route('practice.matters.show', $matter) : route('practice.matters.index') }}">Cancel</a>
            </form>
        </div></div>
    </div></div>
@endsection
