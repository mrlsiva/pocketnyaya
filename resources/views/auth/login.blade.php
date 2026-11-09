@extends('layouts.app')
@section('title', 'Sign in')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card shadow-sm"><div class="card-body p-4">
                <h1 class="h4 mb-3">Sign in</h1>
                <form method="post" action="{{ route('login') }}">@csrf
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1">
                        <label class="form-check-label" for="remember">Keep me signed in</label>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Sign in</button>
                </form>
                <div class="d-flex justify-content-between small mt-3">
                    <a href="{{ route('password.request') }}">Forgot your password?</a>
                    <a href="{{ route('register') }}">Create an account</a>
                </div>
            </div></div>
        </div>
    </div>
@endsection
