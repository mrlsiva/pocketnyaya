@extends('layouts.app')
@section('title', 'Verification request')

@section('content')
    @php($advocate = $request->advocateProfile)
    @php($mediator = $request->mediatorProfile)
    <h1 class="h4 mb-1">{{ $advocate?->user?->name ?? $mediator?->user?->name }}</h1>
    <div class="small text-body-secondary text-capitalize mb-3">{{ $mediator ? 'Mediator' : 'Advocate' }} · {{ str_replace('_', ' ', $request->status) }}</div>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm mb-3"><div class="card-body">
                @if ($advocate)
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Bar Council</dt><dd class="col-sm-7">{{ $advocate->barCouncil?->name }}</dd>
                        <dt class="col-sm-5">Enrollment number</dt><dd class="col-sm-7">{{ $advocate->enrollment_number }}</dd>
                        <dt class="col-sm-5">Enrollment date</dt><dd class="col-sm-7 mb-0">{{ $advocate->enrollment_date->format('d M Y') }}</dd>
                    </dl>
                @else
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Accreditation body</dt><dd class="col-sm-7">{{ $mediator?->accreditation_body }}</dd>
                        <dt class="col-sm-5">Accreditation number</dt><dd class="col-sm-7 mb-0">{{ $mediator?->accreditation_number }}</dd>
                    </dl>
                @endif
            </div></div>
            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Documents</h2>
                @if ($request->status === 'submitted')
                    <p class="small text-body-secondary mb-0">Documents become available when review starts.</p>
                @endif
                @foreach ($documents as $document)
                    <div class="d-flex justify-content-between py-1">
                        <span>{{ $document->title }}</span>
                        @can('download', $document)<a href="{{ route('staff.verification.documents.download', $document) }}">Download</a>@endcan
                    </div>
                @endforeach
            </div></div>
        </div>
        <div class="col-lg-5"><div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Actions</h2>
            @if ($request->status === 'submitted')
                <form method="post" action="{{ route('staff.verification.start', $request) }}">@csrf<button class="btn btn-primary btn-sm" type="submit">Start review</button></form>
            @elseif ($request->status === 'in_review')
                <form class="mb-3" method="post" action="{{ route('staff.verification.decide', $request) }}">@csrf
                    <textarea class="form-control form-control-sm mb-2" name="note" rows="2" placeholder="Decision note (required to reject)" aria-label="Decision note"></textarea>
                    <button class="btn btn-success btn-sm" name="decision" value="approve" type="submit">Approve</button>
                    <button class="btn btn-outline-danger btn-sm" name="decision" value="reject" type="submit">Reject</button>
                </form>
                <form method="post" action="{{ route('staff.verification.info', $request) }}">@csrf
                    <textarea class="form-control form-control-sm mb-2" name="note" rows="2" placeholder="What do you need?" required aria-label="Information needed"></textarea>
                    <button class="btn btn-outline-secondary btn-sm" type="submit">Ask for more information</button>
                </form>
            @else
                <p class="small text-body-secondary mb-0">Waiting for the applicant.</p>
            @endif
        </div></div></div>
    </div>
@endsection
