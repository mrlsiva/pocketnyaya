@extends('layouts.app')
@section('title', 'Reset your password')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card shadow-sm"><div class="card-body p-4">
                <h1 class="h4 mb-3">Reset your password</h1>
                <form method="post" action="{{ route('password.email') }}">@csrf
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Send reset link</button>
                </form>
                <p class="small mt-3 mb-0"><a href="{{ route('login') }}">Back to sign in</a></p>
            </div></div>
        </div>
    </div>
@endsection
