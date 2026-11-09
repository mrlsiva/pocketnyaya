@extends('layouts.app')
@section('title', 'Your profile')

@section('content')
    <h1 class="h4 mb-3">Your profile</h1>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm"><div class="card-body p-4">
                <h2 class="h6 mb-3">Details</h2>
                <form method="post" action="{{ route('profile.update') }}">@csrf @method('put')
                    <div class="mb-3">
                        <label class="form-label" for="name">Full name</label>
                        <input class="form-control" id="name" name="name" value="{{ old('name', $user->name) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" value="{{ $user->email }}" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="phone">Mobile number</label>
                        <input class="form-control" id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="locale">Language</label>
                        <select class="form-select" id="locale" name="locale">
                            @foreach (['en' => 'English', 'ta' => 'Tamil', 'hi' => 'Hindi'] as $code => $label)
                                <option value="{{ $code }}" @selected(old('locale', $user->locale) === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-primary" type="submit">Save</button>
                </form>
            </div></div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm"><div class="card-body p-4">
                <h2 class="h6 mb-3">Change password</h2>
                <form method="post" action="{{ route('profile.password') }}">@csrf @method('put')
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password</label>
                        <input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">New password</label>
                        <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password_confirmation">Repeat new password</label>
                        <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                    </div>
                    <button class="btn btn-primary" type="submit">Change password</button>
                </form>
                <p class="small text-body-secondary mt-3 mb-0">Changing your password signs out your other devices.</p>
            </div></div>
        </div>
    </div>
@endsection
