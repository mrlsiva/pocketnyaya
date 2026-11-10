@extends('layouts.app')
@section('title', 'Drafting assistant')

@section('content')
    <h1 class="h4 mb-3">Drafting assistant</h1>
    @include('practice.nav')

    <div class="alert alert-warning small">
        AI output can be wrong and isn't legal advice. A qualified advocate must check every draft and summary before use.
        Aadhaar, PAN, phone, email, bank and similar numbers are replaced with placeholders such as [PHONE] before the text is sent.
        <strong>Names and addresses are not removed</strong>, so leave them out or use placeholders. Nothing you type here is saved.
    </div>
    @unless ($active)
        <div class="alert alert-secondary small">The AI service is switched off. An administrator must add approved credentials before it can be used.</div>
    @endunless

    @if ($result !== null)
        <div class="card shadow-sm mb-3"><div class="card-body">
            <h2 class="h6">{{ $mode === 'draft' ? 'First draft' : 'Summary' }} <span class="badge text-bg-warning">Check before use</span></h2>
            <pre class="mb-0 small" style="white-space: pre-wrap">{{ $result }}</pre>
        </div></div>
    @endif

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">First draft</h2>
                <form method="post" action="{{ route('practice.ai.draft') }}">@csrf
                    <label class="form-label small" for="document_type">Document</label>
                    <input class="form-control form-control-sm mb-2" id="document_type" name="document_type" maxlength="150" required placeholder="For example: legal notice for recovery of a security deposit" value="{{ old('document_type') }}">
                    <label class="form-label small" for="facts">Facts</label>
                    <textarea class="form-control form-control-sm mb-2" id="facts" name="facts" rows="8" maxlength="8000" required>{{ old('facts') }}</textarea>
                    <button class="btn btn-primary btn-sm" type="submit" @disabled(! $active)>Prepare draft</button>
                </form>
            </div></div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Summary</h2>
                <form method="post" action="{{ route('practice.ai.summarise') }}">@csrf
                    <label class="form-label small" for="text">Text to summarise</label>
                    <textarea class="form-control form-control-sm mb-2" id="text" name="text" rows="11" maxlength="30000" required>{{ old('text') }}</textarea>
                    <button class="btn btn-primary btn-sm" type="submit" @disabled(! $active)>Summarise</button>
                </form>
            </div></div>
        </div>
    </div>
@endsection
