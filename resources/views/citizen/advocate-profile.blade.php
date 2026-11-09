@extends('layouts.app')
@section('title', 'Professional profile')

@section('content')
    <h1 class="h4 mb-3">Professional profile</h1>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm"><div class="card-body p-4">
                <h2 class="h6">Advocate details</h2>
                @php($locked = $profile?->verification_status === 'verified')
                <form method="post" action="{{ route('citizen.advocate.update') }}">@csrf @method('put')
                    <div class="mb-3"><label class="form-label" for="bar_council_id">Bar Council</label>
                        <select class="form-select" id="bar_council_id" name="bar_council_id" @disabled($locked)>
                            @foreach ($councils as $council)<option value="{{ $council->id }}" @selected((int) old('bar_council_id', $profile?->bar_council_id) === $council->id)>{{ $council->name }}</option>@endforeach
                        </select></div>
                    <div class="row">
                        <div class="col-sm-6 mb-3"><label class="form-label" for="enrollment_number">Enrollment number</label>
                            <input class="form-control" id="enrollment_number" name="enrollment_number" value="{{ old('enrollment_number', $profile?->enrollment_number) }}" @disabled($locked)></div>
                        <div class="col-sm-6 mb-3"><label class="form-label" for="enrollment_date">Enrollment date</label>
                            <input class="form-control" id="enrollment_date" name="enrollment_date" type="date" value="{{ old('enrollment_date', $profile?->enrollment_date?->toDateString()) }}" @disabled($locked)></div>
                    </div>
                    @if ($locked)<p class="small text-body-secondary">These are locked because you are verified.</p>@endif
                    <div class="mb-3"><label class="form-label" for="city_id">City</label>
                        <select class="form-select" id="city_id" name="city_id" required>
                            @foreach ($cities as $city)<option value="{{ $city->id }}" @selected((int) old('city_id', $profile?->city_id) === $city->id)>{{ $city->name }}</option>@endforeach
                        </select></div>
                    <div class="mb-3"><label class="form-label" for="bio">About you</label>
                        <textarea class="form-control" id="bio" name="bio" rows="3">{{ old('bio', $profile?->bio) }}</textarea>
                        <div class="form-text">Facts only. Words like "best lawyer" or "guaranteed win" aren't permitted, and your text is checked before it's public.</div></div>
                    <div class="mb-3"><label class="form-label" for="office_address">Office address</label>
                        <textarea class="form-control" id="office_address" name="office_address" rows="2">{{ old('office_address', $profile?->office_address) }}</textarea></div>
                    <div class="row">
                        <div class="col-md-4 mb-3"><label class="form-label" for="practice_area_ids">Practice areas</label>
                            <select class="form-select" id="practice_area_ids" name="practice_area_ids[]" multiple size="6">
                                @foreach ($areas as $area)<option value="{{ $area->id }}" @selected($profile?->practiceAreas->contains('id', $area->id))>{{ $area->name }}</option>@endforeach
                            </select></div>
                        <div class="col-md-4 mb-3"><label class="form-label" for="language_ids">Languages</label>
                            <select class="form-select" id="language_ids" name="language_ids[]" multiple size="6">
                                @foreach ($languages as $language)<option value="{{ $language->id }}" @selected($profile?->languages->contains('id', $language->id))>{{ $language->name }}</option>@endforeach
                            </select></div>
                        <div class="col-md-4 mb-3"><label class="form-label" for="court_ids">Courts</label>
                            <select class="form-select" id="court_ids" name="court_ids[]" multiple size="6">
                                @foreach ($courts as $court)<option value="{{ $court->id }}" @selected($profile?->courts->contains('id', $court->id))>{{ $court->name }}</option>@endforeach
                            </select></div>
                    </div>
                    <button class="btn btn-primary" type="submit">Save</button>
                </form>
            </div></div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Verification</h2>
                <p class="small">Status: <span class="text-capitalize fw-semibold">{{ $profile?->verification_status ?? 'No profile yet' }}</span></p>
                @if ($profile && in_array($profile->verification_status, ['draft', 'rejected'], true))
                    <form method="post" action="{{ route('citizen.advocate.submit') }}">@csrf<button class="btn btn-primary btn-sm" type="submit">Submit for verification</button></form>
                @endif
                @foreach ($requests as $request)
                    <div class="border-top mt-2 pt-2">
                        <div class="small text-capitalize fw-semibold">{{ $request->mediator_profile_id ? 'Mediator' : 'Advocate' }} request: {{ str_replace('_', ' ', $request->status) }}</div>
                        @if ($request->decision_notes)<div class="small">{{ $request->decision_notes }}</div>@endif
                        @foreach ($request->documents as $document)
                            <div class="small"><a href="{{ route('citizen.verification.documents.download', $document) }}">{{ $document->title }}</a></div>
                        @endforeach
                        @if ($request->acceptsDocuments())
                            <form class="mt-2" method="post" action="{{ route('citizen.verification.documents.store', $request) }}" enctype="multipart/form-data">@csrf
                                <input class="form-control form-control-sm mb-1" name="file" type="file" required aria-label="File">
                                <input class="form-control form-control-sm mb-1" name="title" placeholder="For example, Enrollment certificate" required aria-label="Title">
                                <input type="hidden" name="category" value="verification">
                                <button class="btn btn-outline-primary btn-sm" type="submit">Upload</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div></div>
            @if ($canList && $profile)
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Directory listing</h2>
                    <form class="mb-3" method="post" action="{{ route('citizen.advocate.listing') }}">@csrf @method('put')
                        <input type="hidden" name="listed" value="{{ $profile->is_directory_listed ? 0 : 1 }}">
                        <button class="btn btn-outline-primary btn-sm" type="submit">{{ $profile->is_directory_listed ? 'Remove me from the directory' : 'List me in the directory' }}</button>
                    </form>
                    <h2 class="h6">Emergency response</h2>
                    <form class="d-flex gap-2" method="post" action="{{ route('citizen.advocate.responder') }}">@csrf @method('put')
                        <input class="form-control form-control-sm" name="contact_phone" type="tel" placeholder="Contact number" required aria-label="Contact number">
                        <input type="hidden" name="active" value="1">
                        <button class="btn btn-outline-primary btn-sm text-nowrap" type="submit">Join responders</button>
                    </form>
                </div></div>
            @endif
            @unless ($mediator)
                <div class="card shadow-sm"><div class="card-body">
                    <h2 class="h6">Apply as a mediator</h2>
                    <form method="post" action="{{ route('citizen.mediator.apply') }}">@csrf
                        <input class="form-control form-control-sm mb-2" name="accreditation_body" placeholder="Accreditation body" required aria-label="Accreditation body">
                        <input class="form-control form-control-sm mb-2" name="accreditation_number" placeholder="Accreditation number" required aria-label="Accreditation number">
                        <label class="form-label small" for="accreditation_valid_until">Valid until</label>
                        <input class="form-control form-control-sm mb-2" id="accreditation_valid_until" name="accreditation_valid_until" type="date">
                        <button class="btn btn-outline-primary btn-sm" type="submit">Apply</button>
                    </form>
                </div></div>
            @endunless
        </div>
    </div>
@endsection
