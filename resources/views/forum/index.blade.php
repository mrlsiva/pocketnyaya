@extends('layouts.app')
@section('title', 'Community questions')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <h1 class="h4 mb-0">Community questions</h1>
        @auth <a class="btn btn-primary btn-sm" href="{{ route('citizen.forum.create') }}">Ask a question</a> @endauth
    </div>
    @include('forum.disclaimer')
    <form class="row g-2 mb-3" method="get">
        <div class="col"><input class="form-control form-control-sm" name="q" value="{{ $search }}" placeholder="Search questions" aria-label="Search"></div>
        <div class="col-auto"><select class="form-select form-select-sm" name="practice_area_id" aria-label="Practice area"><option value="">All areas</option>
            @foreach ($areas as $area)<option value="{{ $area->id }}" @selected($areaId === (string) $area->id)>{{ $area->name }}</option>@endforeach
        </select></div>
        <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Search</button></div>
    </form>
    @forelse ($questions as $question)
        <div class="card shadow-sm mb-2"><div class="card-body py-2">
            <a class="fw-semibold" href="{{ route('forum.show', $question) }}">{{ $question->title }}</a>
            <div class="small text-body-secondary">{{ $question->public_alias }} · {{ $question->practiceArea?->name ?? 'General' }} · {{ $question->answers_count }} {{ \Illuminate\Support\Str::plural('answer', $question->answers_count) }}</div>
        </div></div>
    @empty
        <p class="text-body-secondary">No questions found.</p>
    @endforelse
    <div class="mt-3">{{ $questions->links('pagination::bootstrap-5') }}</div>
@endsection
