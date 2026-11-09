@extends('layouts.app')
@section('title', $advocate['name'])

@section('content')
    <div class="row justify-content-center"><div class="col-lg-8">
        <div class="card shadow-sm"><div class="card-body p-4">
            <h1 class="h4">{{ $advocate['name'] }}</h1>
            <dl class="row mb-0">
                <dt class="col-sm-4">Bar Council</dt><dd class="col-sm-8">{{ $advocate['bar_council'] }}</dd>
                <dt class="col-sm-4">Enrollment number</dt><dd class="col-sm-8">{{ $advocate['enrollment_number'] }}</dd>
                <dt class="col-sm-4">Years at the Bar</dt><dd class="col-sm-8">{{ $advocate['years_at_bar'] }}</dd>
                <dt class="col-sm-4">City</dt><dd class="col-sm-8">{{ $advocate['city'] }}</dd>
                <dt class="col-sm-4">Practice areas</dt><dd class="col-sm-8">{{ implode(', ', $advocate['practice_areas']) ?: '—' }}</dd>
                <dt class="col-sm-4">Languages</dt><dd class="col-sm-8">{{ implode(', ', $advocate['languages']) ?: '—' }}</dd>
                <dt class="col-sm-4">Qualifications</dt>
                <dd class="col-sm-8">
                    @forelse ($advocate['qualifications'] as $q)
                        <div>{{ $q['degree'] }}, {{ $q['institution'] }} ({{ $q['year_of_passing'] }})</div>
                    @empty
                        —
                    @endforelse
                </dd>
                @if ($advocate['bio'])<dt class="col-sm-4">About</dt><dd class="col-sm-8">{{ $advocate['bio'] }}</dd>@endif
            </dl>
            <hr>
            @auth
                <a class="btn btn-primary btn-sm" href="{{ route('citizen.consultations.create', $profileId) }}">Request a consultation</a>
            @else
                <a class="btn btn-outline-primary btn-sm" href="{{ route('login') }}">Sign in to request a consultation</a>
            @endauth
            <p class="small text-body-secondary mt-3 mb-0">Biographical information only. This isn't advertising, and any contact you make is your own choice.</p>
        </div></div>
    </div></div>
@endsection
