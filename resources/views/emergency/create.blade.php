@extends('layouts.app')
@section('title', 'Urgent help')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-7">
        <div class="alert alert-danger" role="alert"><strong>In immediate danger?</strong> Call <a class="alert-link" href="tel:112">112</a> or the Women Helpline <a class="alert-link" href="tel:181">181</a> now. This form is not a substitute for emergency services.</div>
        <div class="card shadow-sm mb-3"><div class="card-body p-4">
            <h1 class="h4">Request urgent legal help</h1>
            <form method="post" action="{{ route('citizen.emergency.store') }}">@csrf
                <div class="mb-3"><label class="form-label" for="category">What is this about?</label>
                    <select class="form-select" id="category" name="category">
                        <option value="domestic_violence">Domestic violence</option><option value="harassment">Harassment</option>
                        <option value="protection_order">Protection order</option><option value="other">Something else</option>
                    </select></div>
                <div class="row">
                    <div class="col-sm-6 mb-3"><label class="form-label" for="city_id">City</label>
                        <select class="form-select" id="city_id" name="city_id" required>
                            @foreach ($cities as $city)<option value="{{ $city->id }}">{{ $city->name }}</option>@endforeach
                        </select></div>
                    <div class="col-sm-6 mb-3"><label class="form-label" for="preferred_language_id">Preferred language</label>
                        <select class="form-select" id="preferred_language_id" name="preferred_language_id"><option value="">Any</option>
                            @foreach ($languages as $language)<option value="{{ $language->id }}">{{ $language->name }}</option>@endforeach
                        </select></div>
                </div>
                <div class="mb-3"><label class="form-label" for="contact_phone">A safe number to reach you</label>
                    <input class="form-control" id="contact_phone" name="contact_phone" type="tel" value="{{ old('contact_phone') }}" required></div>
                <div class="mb-3"><label class="form-label" for="description">What's happening?</label>
                    <textarea class="form-control" id="description" name="description" rows="4" required>{{ old('description') }}</textarea></div>
                <button class="btn btn-danger" type="submit">Send urgent request</button>
            </form>
        </div></div>
        @if ($mine->isNotEmpty())
            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">My requests</h2>
                @foreach ($mine as $request)
                    <div class="small py-1">{{ $request->created_at->format('d M Y H:i') }} · {{ str_replace('_', ' ', $request->category) }} · <span class="text-capitalize">{{ $request->status }}</span></div>
                @endforeach
            </div></div>
        @endif
    </div></div>
@endsection
