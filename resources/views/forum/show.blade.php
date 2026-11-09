@extends('layouts.app')
@section('title', $question->title)

@php
    $user = auth()->user();
    $canComment = $user && \App\Support\AccessContext::citizenCan($user, 'forum.comments.create');
    $canReport = $user && \App\Support\AccessContext::citizenCan($user, 'forum.reports.create');
    $canAnswer = $user && \App\Support\AccessContext::citizenCan($user, 'forum.answers.create') && $user->advocateProfile?->verification_status === 'verified';
@endphp

@section('content')
    <div class="row justify-content-center"><div class="col-lg-9">
        @include('forum.disclaimer')
        <div class="card shadow-sm mb-3"><div class="card-body">
            <h1 class="h5">{{ $question->title }}</h1>
            <p style="white-space: pre-line">{{ $question->body }}</p>
            <div class="small text-body-secondary">Asked by {{ $question->public_alias }}</div>
            @include('forum.post-actions', ['type' => 'forum_question', 'id' => $question->id, 'canComment' => $canComment, 'canReport' => $canReport, 'reasons' => $reasons, 'postComments' => $comments[$question->id] ?? collect()])
        </div></div>

        <h2 class="h6">Answers from verified advocates</h2>
        @forelse ($answers as $answer)
            <div class="card shadow-sm mb-2"><div class="card-body">
                <p style="white-space: pre-line">{{ $answer->body }}</p>
                <div class="small text-body-secondary">Adv. {{ $answer->advocate?->user?->name }} · {{ $answer->published_at?->format('d M Y') }}</div>
                @include('forum.post-actions', ['type' => 'forum_answer', 'id' => $answer->id, 'canComment' => $canComment, 'canReport' => $canReport, 'reasons' => $reasons, 'postComments' => $comments[$answer->id] ?? collect()])
            </div></div>
        @empty
            <p class="text-body-secondary">No answers yet.</p>
        @endforelse

        @if ($canAnswer)
            <div class="card shadow-sm mt-3"><div class="card-body">
                <h2 class="h6">Answer this question</h2>
                <form method="post" action="{{ route('citizen.forum.answer', $question) }}">@csrf
                    <textarea class="form-control mb-2" name="body" rows="5" required aria-label="Your answer"></textarea>
                    <div class="form-text mb-2">Give general information only. Don't include fees, contact details or anything that promotes you.</div>
                    <button class="btn btn-primary btn-sm" type="submit">Post answer</button>
                </form>
            </div></div>
        @endif
    </div></div>
@endsection
