@extends('layouts.app')
@section('title', 'Set up two-step sign-in')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm"><div class="card-body p-4">
                <h1 class="h4 mb-3">Set up two-step sign-in</h1>
                <p>Platform staff must use an authenticator app. Add this setup key to your app, then enter the 6-digit code it shows.</p>
                <p class="font-monospace bg-body-tertiary border rounded p-2 text-break">{{ $secret }}</p>
                <form method="post" action="{{ route('mfa.setup') }}">@csrf
                    <div class="mb-3">
                        <label class="form-label" for="code">6-digit code</label>
                        <input class="form-control" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
                    </div>
                    <button class="btn btn-primary" type="submit">Turn on</button>
                </form>
            </div></div>
        </div>
    </div>
@endsection
