@extends('layouts.app')
@section('title', 'Create an account')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm"><div class="card-body p-4">
                <h1 class="h4 mb-3">Create an account</h1>
                <form method="post" action="{{ route('register') }}">@csrf
                    <div class="mb-3">
                        <label class="form-label" for="name">Full name</label>
                        <input class="form-control" id="name" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="phone">Mobile number <span class="text-body-secondary">(optional)</span></label>
                        <input class="form-control" id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel">
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label" for="password">Password</label>
                            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" required>
                            <div class="form-text">At least 10 characters, with letters and numbers.</div>
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label" for="password_confirmation">Repeat password</label>
                            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="consent" name="consent" value="1" required>
                        <label class="form-check-label small" for="consent">I agree that PocketNyaya may use my details to run my account and store the documents I upload. I can review this under Privacy and my data.</label>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Create account</button>
                </form>
                <p class="small mt-3 mb-0">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
            </div></div>
        </div>
    </div>
@endsection
