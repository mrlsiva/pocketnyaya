@extends('layouts.app')
@section('title', 'My personal account')

@section('content')
    <h1 class="h4 mb-3">My personal account</h1>
    <div class="row g-3">
        @foreach ([
            ['My document vault', "Keep identity papers, property records and legal notices encrypted, and see what's been shared with you.", route('citizen.vault.index'), 'Open vault'],
            ['Find an advocate', 'Look up verified advocates by city, practice area and language.', route('directory.index'), 'Search directory'],
            ['My consultations', 'Follow your requests and message your advocate.', route('citizen.consultations.index'), 'View consultations'],
            ['Community questions', 'Ask a question without your name showing, and read answers from verified advocates.', route('forum.index'), 'Open forum'],
            ['Dispute resolution', 'Try to settle a small dispute online, with a mediator if needed.', route('citizen.odr.index'), 'Open disputes'],
            ['Privacy and my data', 'See your consents, download your data or ask for it to be erased.', route('citizen.privacy.index'), 'Manage privacy'],
            ['Urgent help', 'A priority channel for domestic violence, harassment and protection needs.', route('citizen.emergency.create'), 'Get urgent help'],
            ['Professional profile', 'Advocate or mediator details, verification and your directory listing.', route('citizen.advocate.edit'), 'Manage profile'],
            ['Set up a practice', 'Manage clients, matters and your court diary as a solo lawyer or a firm.', route('citizen.practice.create'), 'Set up'],
        ] as [$title, $text, $link, $label])
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm h-100"><div class="card-body d-flex flex-column">
                    <h2 class="h6">{{ $title }}</h2>
                    <p class="small text-body-secondary flex-grow-1">{{ $text }}</p>
                    <div><a class="btn btn-primary btn-sm" href="{{ $link }}">{{ $label }}</a></div>
                </div></div>
            </div>
        @endforeach
    </div>
@endsection
