@extends('layouts.app')
@section('title', 'Verify your email')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm"><div class="card-body p-4">
                <h1 class="h4 mb-3">Verify your email</h1>
                <p>We've sent a verification link to your email address. Open it to continue.</p>
                @if (config('mail.default') === 'log')
                    <p class="small text-body-secondary">Development mode: emails are written to <code>storage/logs</code>, not sent.</p>
                @endif
                <form method="post" action="{{ route('verification.send') }}">@csrf
                    <button class="btn btn-outline-primary" type="submit">Send the link again</button>
                </form>
            </div></div>
        </div>
    </div>
@endsection
