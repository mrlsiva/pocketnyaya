@extends('layouts.app')

@section('content')
    <div class="py-4 py-md-5">
        <h1 class="display-6 fw-semibold">Pocket-sized access to justice</h1>
        <p class="lead text-body-secondary col-lg-8">Practice management for advocates and law firms, and a personal space for citizens to organise their legal matters.</p>
        <div class="d-flex flex-wrap gap-2">
            @auth
                <a class="btn btn-primary" href="{{ route('workspaces.index') }}">Open my workspaces</a>
            @else
                <a class="btn btn-primary" href="{{ route('register') }}">Create an account</a>
                <a class="btn btn-outline-secondary" href="{{ route('login') }}">Sign in</a>
            @endauth
        </div>
    </div>
@endsection
