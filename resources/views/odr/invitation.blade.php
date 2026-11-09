@extends('layouts.app')
@section('title', 'Dispute invitation')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-7"><div class="card shadow-sm"><div class="card-body">
        <h1 class="h5">You've been invited to a dispute</h1>
        <p>Dispute {{ $dispute?->dispute_number }}. You're invited as {{ str_replace('_', ' ', $participant->participant_role) }}. Taking part is voluntary.</p>
        @if ($disclaimer)<div class="alert alert-light border small">{{ $disclaimer->body }}</div>@endif
        @auth
            <div class="d-flex gap-2">
                <form method="post" action="{{ route('odr.invitations.accept', $token) }}">@csrf<button class="btn btn-primary" type="submit">Join</button></form>
                <form method="post" action="{{ route('odr.invitations.decline', $token) }}">@csrf<button class="btn btn-outline-secondary" type="submit">Decline</button></form>
            </div>
        @else
            <p class="mb-0"><a href="{{ route('login') }}">Sign in</a> or <a href="{{ route('register') }}">create an account</a> using the email address this invitation was sent to, then open this link again.</p>
        @endauth
    </div></div></div></div>
@endsection
