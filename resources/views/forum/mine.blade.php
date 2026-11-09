@extends('layouts.app')
@section('title', 'My questions')

@section('content')
    <h1 class="h4 mb-3">My questions</h1>
    <div class="card shadow-sm"><div class="list-group list-group-flush">
        @forelse ($questions as $question)
            <div class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                @if ($question->status === 'published')<a href="{{ route('forum.show', $question) }}">{{ $question->title }}</a>@else<span>{{ $question->title }}</span>@endif
                <span>
                    @if (in_array($question->status, ['pending_screening', 'flagged'], true))<a class="small me-2" href="{{ route('citizen.forum.edit', $question) }}">Edit</a>@endif
                    <span class="badge text-bg-light text-capitalize">{{ str_replace('_', ' ', $question->status) }}</span>
                </span>
            </div>
        @empty
            <div class="list-group-item text-body-secondary">You haven't asked anything yet.</div>
        @endforelse
    </div></div>
    <div class="mt-3">{{ $questions->links('pagination::bootstrap-5') }}</div>
@endsection
