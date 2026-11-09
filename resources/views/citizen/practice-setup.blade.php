@extends('layouts.app')
@section('title', 'Set up a practice')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-8">
        <div class="card shadow-sm"><div class="card-body p-4">
            <h1 class="h4 mb-3">Set up a practice</h1>
            <form method="post" action="{{ route('citizen.practice.store') }}">@csrf
                <div class="mb-3">
                    <label class="form-label" for="type">Type</label>
                    <select class="form-select" id="type" name="type" required>
                        <option value="solo" @selected(old('type') === 'solo')>Independent lawyer (solo practice)</option>
                        <option value="firm" @selected(old('type') === 'firm')>Law firm (you become Managing Partner)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="name">Practice or firm name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name') }}" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="state_code">State</label>
                    <select class="form-select" id="state_code" name="state_code" required>
                        <option value="">Choose…</option>
                        @foreach ($states as $state)
                            <option value="{{ $state->code }}" @selected(old('state_code') === $state->code)>{{ $state->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($profile === null)
                    <hr>
                    <h2 class="h6">Your advocate details</h2>
                    <p class="small text-body-secondary">You can start working straight away. Public features stay locked until your details are verified.</p>
                    <div class="mb-3">
                        <label class="form-label" for="bar_council_id">Bar Council</label>
                        <select class="form-select" id="bar_council_id" name="bar_council_id" required>
                            <option value="">Choose…</option>
                            @foreach ($councils as $council)
                                <option value="{{ $council->id }}" @selected((int) old('bar_council_id') === $council->id)>{{ $council->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label" for="enrollment_number">Enrollment number</label>
                            <input class="form-control" id="enrollment_number" name="enrollment_number" value="{{ old('enrollment_number') }}" required>
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label" for="enrollment_date">Enrollment date</label>
                            <input class="form-control" id="enrollment_date" name="enrollment_date" type="date" value="{{ old('enrollment_date') }}" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="city_id">City</label>
                        <select class="form-select" id="city_id" name="city_id" required>
                            <option value="">Choose…</option>
                            @foreach ($cities as $city)
                                <option value="{{ $city->id }}" @selected((int) old('city_id') === $city->id)>{{ $city->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <button class="btn btn-primary" type="submit">Create practice</button>
            </form>
        </div></div>
    </div></div>
@endsection
