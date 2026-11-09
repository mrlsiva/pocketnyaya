@extends('layouts.app')
@section('title', 'Invitation')

@section('content')
    <div class="row justify-content-center"><div class="col-md-7 col-lg-5">
        <div class="card shadow-sm"><div class="card-body p-4">
            <h1 class="h4">Join {{ $invitation->name }}</h1>
            <p>You've been invited as {{ $invitation->role_name }}.</p>
            @auth
                <form method="post" action="{{ route('invitations.accept', $token) }}">@csrf<button class="btn btn-primary" type="submit">Accept invitation</button></form>
            @else
                <p class="small text-body-secondary">Sign in or register with the email address this invitation was sent to, then open this link again.</p>
                <a class="btn btn-primary btn-sm" href="{{ route('login') }}">Sign in</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('register') }}">Register</a>
            @endauth
        </div></div>
    </div></div>
@endsection
