<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="d-flex flex-column min-vh-100 bg-body-tertiary">
    <nav class="navbar navbar-expand-md bg-white border-bottom">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="{{ route('home') }}">{{ config('app.name') }}</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
                    <li class="nav-item"><a class="nav-link" href="{{ route('directory.index') }}">Find an advocate</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('forum.index') }}">Community</a></li>
                    @auth
                        <li class="nav-item"><a class="nav-link" href="{{ route('workspaces.index') }}">Workspaces</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('profile.edit') }}">Profile</a></li>
                        <li class="nav-item">
                            <form method="post" action="{{ route('logout') }}">@csrf
                                <button class="btn btn-link nav-link" type="submit">Sign out</button>
                            </form>
                        </li>
                    @else
                        <li class="nav-item"><a class="nav-link" href="{{ route('login') }}">Sign in</a></li>
                        <li class="nav-item"><a class="btn btn-primary btn-sm" href="{{ route('register') }}">Register</a></li>
                    @endauth
                </ul>
            </div>
        </div>
    </nav>

    <main class="container py-4 flex-grow-1">
        @include('partials.alerts')
        @yield('content')
    </main>

    <footer class="border-top bg-white py-3">
        <div class="container small text-body-secondary">
            This platform does not advertise or solicit legal work. Your use of it is voluntary.
            Information here is not legal advice.
        </div>
    </footer>
</body>
</html>
