@extends('layouts.app')
@section('title', 'Disclaimers and purposes')

@section('content')
    <h1 class="h4 mb-1">Disclaimers and consent purposes</h1>
    <p class="small text-body-secondary">Existing versions can't be edited. To change wording, add a new version.</p>
    @include('staff.privacy.nav')
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Disclaimers</h2>
                @foreach ($disclaimers as $d)
                    <div class="py-1 border-bottom small"><span class="fw-semibold">{{ $d->code }} v{{ $d->version }}</span>: {{ $d->title }}</div>
                @endforeach
                <form class="mt-3" method="post" action="{{ route('staff.privacy.disclaimers.store') }}">@csrf
                    <input class="form-control form-control-sm mb-2" name="code" placeholder="code" required aria-label="Code">
                    <input class="form-control form-control-sm mb-2" name="title" placeholder="Title" required aria-label="Title">
                    <textarea class="form-control form-control-sm mb-2" name="body" rows="3" placeholder="Wording" required aria-label="Wording"></textarea>
                    <input class="form-control form-control-sm mb-2" name="effective_from" type="date" value="{{ now()->toDateString() }}" required aria-label="Effective from">
                    <button class="btn btn-primary btn-sm" type="submit">Add version</button>
                </form>
            </div></div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Consent purposes</h2>
                @foreach ($purposes as $p)
                    <div class="py-1 border-bottom small"><span class="fw-semibold">{{ $p->code }} v{{ $p->version }}</span>: {{ $p->name }} @if ($p->is_mandatory)<span class="badge text-bg-light">Mandatory</span>@endif</div>
                @endforeach
                <form class="mt-3" method="post" action="{{ route('staff.privacy.purposes.store') }}">@csrf
                    <input class="form-control form-control-sm mb-2" name="code" placeholder="code" required aria-label="Code">
                    <input class="form-control form-control-sm mb-2" name="name" placeholder="Name" required aria-label="Name">
                    <textarea class="form-control form-control-sm mb-2" name="description" rows="3" placeholder="Plain description" required aria-label="Description"></textarea>
                    <input class="form-control form-control-sm mb-2" name="effective_from" type="date" value="{{ now()->toDateString() }}" required aria-label="Effective from">
                    <input type="hidden" name="is_mandatory" value="0">
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="is_mandatory" name="is_mandatory" value="1"><label class="form-check-label small" for="is_mandatory">Needed to run the account</label></div>
                    <button class="btn btn-primary btn-sm" type="submit">Add version</button>
                </form>
            </div></div>
        </div>
    </div>
@endsection
