@extends('layouts.app')
@section('title', 'Settlement templates')

@section('content')
    <h1 class="h4 mb-1">Settlement templates</h1>
    <p class="small text-body-secondary">A template can be used only after legal review is recorded. In the wording, use {dispute_number}, {claimant}, {respondent} and {terms}.</p>
    <div class="row g-3">
        <div class="col-lg-7">
            @forelse ($templates as $template)
                <div class="card shadow-sm mb-2"><div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div><span class="fw-semibold">{{ $template->title }}</span> <span class="small text-body-secondary">{{ str_replace('_', ' ', $template->category) }} v{{ $template->version }}</span></div>
                        <span class="badge text-bg-light align-self-start text-capitalize">{{ $template->legal_review_status }}</span>
                    </div>
                    <form class="mt-2" method="post" action="{{ route('staff.odr.templates.update', $template) }}">@csrf @method('put')
                        @if ($template->legal_review_status === 'draft')<button class="btn btn-outline-primary btn-sm" name="legal_review_status" value="reviewed" type="submit">Record legal review</button>@endif
                        @if ($template->legal_review_status !== 'retired')<button class="btn btn-outline-secondary btn-sm" name="legal_review_status" value="retired" type="submit">Retire</button>@endif
                    </form>
                </div></div>
            @empty
                <p class="text-body-secondary">No templates yet.</p>
            @endforelse
        </div>
        <div class="col-lg-5"><div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Add a template</h2>
            <form method="post" action="{{ route('staff.odr.templates.store') }}">@csrf
                <select class="form-select form-select-sm mb-2" name="category" aria-label="Category">@foreach ($categories as $c)<option value="{{ $c }}">{{ ucfirst(str_replace('_', ' ', $c)) }}</option>@endforeach</select>
                <input class="form-control form-control-sm mb-2" name="title" placeholder="Title" required aria-label="Title">
                <textarea class="form-control form-control-sm mb-2" name="body_template" rows="6" required aria-label="Wording"></textarea>
                <button class="btn btn-primary btn-sm" type="submit">Save draft</button>
            </form>
        </div></div></div>
    </div>
@endsection
