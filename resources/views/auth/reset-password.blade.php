@extends('layouts.app')
@section('title', 'Choose a new password')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card shadow-sm"><div class="card-body p-4">
                <h1 class="h4 mb-3">Choose a new password</h1>
                <form method="post" action="{{ route('password.update') }}">@csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $email) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">New password</label>
                        <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password_confirmation">Repeat new password</label>
                        <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Reset password</button>
                </form>
            </div></div>
        </div>
    </div>
@endsection
