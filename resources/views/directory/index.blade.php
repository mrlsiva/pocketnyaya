@extends('layouts.app')
@section('title', 'Find an advocate')

@section('content')
    <h1 class="h4 mb-1">Find an advocate</h1>
    <div class="alert alert-info small" role="note">
        This directory lists verified advocates' biographical details so that you can look them up yourself. It isn't advertising or a recommendation, and it holds no ratings or rankings. Your use of it is voluntary.
    </div>
    <form class="row g-2 mb-3" method="get">
        <div class="col-md-3"><select class="form-select" name="city_id" aria-label="City"><option value="">Any city</option>
            @foreach ($cities as $city)<option value="{{ $city->id }}" @selected(($filters['city_id'] ?? '') == $city->id)>{{ $city->name }}</option>@endforeach
        </select></div>
        <div class="col-md-4"><select class="form-select" name="practice_area_id" aria-label="Practice area"><option value="">Any practice area</option>
            @foreach ($areas as $area)<option value="{{ $area->id }}" @selected(($filters['practice_area_id'] ?? '') == $area->id)>{{ $area->name }}</option>@endforeach
        </select></div>
        <div class="col-md-3"><select class="form-select" name="language_id" aria-label="Language"><option value="">Any language</option>
            @foreach ($languages as $language)<option value="{{ $language->id }}" @selected(($filters['language_id'] ?? '') == $language->id)>{{ $language->name }}</option>@endforeach
        </select></div>
        <div class="col-md-2"><button class="btn btn-outline-secondary w-100" type="submit">Filter</button></div>
    </form>
    <div class="row g-3">
        @forelse ($advocates as $advocate)
            <div class="col-md-6 col-lg-4"><div class="card shadow-sm h-100"><div class="card-body">
                <h2 class="h6 mb-1"><a href="{{ route('directory.show', $advocate->id) }}">{{ $advocate->user?->name }}</a></h2>
                <div class="small text-body-secondary">{{ $advocate->city?->name }} · {{ $advocate->years_at_bar }} years at the Bar</div>
                <div class="small">{{ $advocate->practiceAreas->pluck('name')->implode(', ') }}</div>
            </div></div></div>
        @empty
            <div class="col-12 text-body-secondary">No advocates match these filters.</div>
        @endforelse
    </div>
    <div class="mt-3">{{ $advocates->links('pagination::bootstrap-5') }}</div>
    <p class="small text-body-secondary mt-3 mb-0">Listed in alphabetical order.</p>
@endsection
