@extends('layouts.app')
@php($editing = isset($question))
@section('title', $editing ? 'Edit your question' : 'Ask a question')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-8">
        <h1 class="h4 mb-3">{{ $editing ? 'Edit your question' : 'Ask a question' }}</h1>
        @include('forum.disclaimer')
        <div class="card shadow-sm"><div class="card-body">
            <form method="post" action="{{ $editing ? route('citizen.forum.update', $question) : route('citizen.forum.store') }}">@csrf
                @if ($editing) @method('put') @endif
                <div class="mb-3"><label class="form-label" for="title">Your question in a sentence</label>
                    <input class="form-control" id="title" name="title" value="{{ old('title', $question->title ?? '') }}" required></div>
                <div class="mb-3"><label class="form-label" for="body">Details</label>
                    <textarea class="form-control" id="body" name="body" rows="6" required>{{ old('body', $question->body ?? '') }}</textarea>
                    <div class="form-text">Your name isn't shown. Leave out names, phone numbers and addresses.</div></div>
                <div class="mb-3"><label class="form-label" for="practice_area_id">Area of law <span class="text-body-secondary">(optional)</span></label>
                    <select class="form-select" id="practice_area_id" name="practice_area_id"><option value="">Not sure</option>
                        @foreach ($areas as $area)<option value="{{ $area->id }}" @selected(old('practice_area_id', $question->practice_area_id ?? null) == $area->id)>{{ $area->name }}</option>@endforeach
                    </select></div>
                <button class="btn btn-primary" type="submit">{{ $editing ? 'Save and send for review' : 'Post question' }}</button>
            </form>
        </div></div>
    </div></div>
@endsection
