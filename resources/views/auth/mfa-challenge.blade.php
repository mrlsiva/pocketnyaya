@extends('layouts.app')
@section('title', 'Two-step sign-in')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card shadow-sm"><div class="card-body p-4">
                <h1 class="h4 mb-3">Two-step sign-in</h1>
                <form method="post" action="{{ route('mfa.challenge') }}">@csrf
                    <div class="mb-3">
                        <label class="form-label" for="code">6-digit code from your app</label>
                        <input class="form-control" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Continue</button>
                </form>
            </div></div>
        </div>
    </div>
@endsection
