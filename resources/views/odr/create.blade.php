@extends('layouts.app')
@section('title', 'Start a dispute')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-8">
        <h1 class="h4 mb-3">Start a dispute</h1>
        @if ($disclaimer)
            <div class="alert alert-light border small"><strong>{{ $disclaimer->title }}.</strong> {{ $disclaimer->body }}</div>
        @endif
        <div class="card shadow-sm"><div class="card-body">
            <form method="post" action="{{ route('citizen.odr.store') }}">@csrf
                <div class="mb-3"><label class="form-label" for="category">What is it about?</label>
                    <select class="form-select" id="category" name="category">@foreach ($categories as $c)<option value="{{ $c }}" @selected(old('category') === $c)>{{ ucfirst(str_replace('_', ' ', $c)) }}</option>@endforeach</select></div>
                <div class="mb-3"><label class="form-label" for="title">Short title</label>
                    <input class="form-control" id="title" name="title" value="{{ old('title') }}" required></div>
                <div class="mb-3"><label class="form-label" for="description">What happened?</label>
                    <textarea class="form-control" id="description" name="description" rows="5" required>{{ old('description') }}</textarea></div>
                <div class="mb-3"><label class="form-label" for="claim_amount">Amount in dispute, if any</label>
                    <input class="form-control" id="claim_amount" name="claim_amount" value="{{ old('claim_amount') }}"></div>
                <fieldset class="mb-3"><legend class="form-label fs-6">A few questions, to check this is suitable</legend>
                    @foreach ($questions as $key => $question)
                        <div class="form-check"><input type="hidden" name="answers[{{ $key }}]" value="0">
                            <input class="form-check-input" type="checkbox" id="q-{{ $key }}" name="answers[{{ $key }}]" value="1">
                            <label class="form-check-label" for="q-{{ $key }}">{{ $question }}</label></div>
                    @endforeach
                </fieldset>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="accept_disclaimer" name="accept_disclaimer" value="1" required>
                    <label class="form-check-label" for="accept_disclaimer">I have read and understood the notice above.</label></div>
                <button class="btn btn-primary" type="submit">Continue</button>
            </form>
        </div></div>
    </div></div>
@endsection
