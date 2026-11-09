@extends('layouts.app')
@section('title', 'Practice settings')

@section('content')
    <h1 class="h4 mb-3">Practice settings</h1>
    @include('practice.nav')
    @if ($subscription)
        <div class="card shadow-sm mb-3"><div class="card-body">
            <h2 class="h6">Plan</h2>
            @if ($subscription['plan'])
                <p class="small mb-2">{{ $subscription['plan']->name }} <span class="text-body-secondary text-capitalize">({{ str_replace('_', ' ', $subscription['plan']->status) }})</span></p>
            @else
                <p class="small mb-2">No plan assigned, so there are no limits.</p>
            @endif
            <div class="row small">
                @foreach (\App\Services\SubscriptionLimits::LIMITS as $key => $label)
                    <div class="col-sm-4">{{ ucfirst($label) }}: {{ $subscription['usage'][$key]['used'] }}{{ $subscription['usage'][$key]['limit'] !== null ? ' of '.$subscription['usage'][$key]['limit'] : '' }}</div>
                @endforeach
            </div>
        </div></div>
    @endif
    <div class="row g-3">
        @if ($canViewSettings)
            <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-body">
                <h2 class="h6">Practice details</h2>
                <form method="post" action="{{ route('practice.settings.update') }}">@csrf @method('put')
                    <fieldset @disabled(! $canUpdate)>
                        <label class="form-label small" for="name">Name</label>
                        <input class="form-control form-control-sm mb-2" id="name" name="name" value="{{ old('name', $tenant->name) }}" required>
                        <label class="form-label small" for="legal_name">Legal name</label>
                        <input class="form-control form-control-sm mb-2" id="legal_name" name="legal_name" value="{{ old('legal_name', $tenant->legal_name) }}">
                        <label class="form-label small" for="gst_registration_type">GST registration</label>
                        <select class="form-select form-select-sm mb-2" id="gst_registration_type" name="gst_registration_type">
                            @foreach (['unregistered' => 'Unregistered', 'regular' => 'Regular', 'composition' => 'Composition'] as $value => $label)<option value="{{ $value }}" @selected(old('gst_registration_type', $tenant->gst_registration_type) === $value)>{{ $label }}</option>@endforeach
                        </select>
                        <label class="form-label small" for="gstin">GSTIN</label>
                        <input class="form-control form-control-sm mb-2" id="gstin" name="gstin" value="{{ old('gstin', $tenant->gstin) }}">
                        <label class="form-label small" for="state_code">State</label>
                        <select class="form-select form-select-sm mb-2" id="state_code" name="state_code">
                            @foreach ($states as $state)<option value="{{ $state->code }}" @selected(old('state_code', $tenant->state_code) === $state->code)>{{ $state->name }}</option>@endforeach
                        </select>
                        <label class="form-label small" for="address_line1">Address</label>
                        <input class="form-control form-control-sm mb-2" id="address_line1" name="address_line1" value="{{ old('address_line1', $tenant->address_line1) }}">
                        <label class="form-label small" for="pincode">PIN code</label>
                        <input class="form-control form-control-sm mb-2" id="pincode" name="pincode" value="{{ old('pincode', $tenant->pincode) }}">
                        @if ($canUpdate)<button class="btn btn-primary btn-sm" type="submit">Save</button>@endif
                    </fieldset>
                </form>
            </div></div></div>
        @endif
        <div class="col-lg-6">
            @if ($canBranches)
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Branches</h2>
                    @foreach ($branches as $branch)<div class="small py-1 border-bottom">{{ $branch->name }} @if ($branch->is_head_office)<span class="badge text-bg-light">Head office</span>@endif</div>@endforeach
                    <form class="mt-2" method="post" action="{{ route('practice.settings.branches') }}">@csrf
                        <input class="form-control form-control-sm mb-1" name="name" placeholder="Branch name" required aria-label="Branch name">
                        <select class="form-select form-select-sm mb-1" name="state_code" aria-label="State">@foreach ($states as $state)<option value="{{ $state->code }}">{{ $state->name }}</option>@endforeach</select>
                        <select class="form-select form-select-sm mb-1" name="city_id" required aria-label="City"><option value="">City…</option>@foreach ($cities as $city)<option value="{{ $city->id }}">{{ $city->name }}</option>@endforeach</select>
                        <input type="hidden" name="is_head_office" value="0">
                        <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="is_head_office" name="is_head_office" value="1"><label class="form-check-label small" for="is_head_office">Head office</label></div>
                        <button class="btn btn-outline-primary btn-sm" type="submit">Add branch</button>
                    </form>
                </div></div>
            @endif
            @if ($canGroups)
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Practice groups</h2>
                    @foreach ($groups as $group)<div class="small py-1 border-bottom">{{ $group->name }}</div>@endforeach
                    <form class="mt-2 d-flex gap-1" method="post" action="{{ route('practice.settings.groups') }}">@csrf
                        <input class="form-control form-control-sm" name="name" placeholder="Group name" required aria-label="Group name">
                        <button class="btn btn-outline-primary btn-sm" type="submit">Add</button>
                    </form>
                </div></div>
            @endif
            @if ($canAvailability)
                <div class="card shadow-sm"><div class="card-body">
                    <h2 class="h6">My availability</h2>
                    @foreach ($availability as $slot)<div class="small py-1 border-bottom text-capitalize">{{ $slot->availability }}: {{ $slot->starts_on }} to {{ $slot->ends_on }}</div>@endforeach
                    <form class="mt-2" method="post" action="{{ route('practice.settings.availability') }}">@csrf
                        <div class="row g-1 mb-1">
                            <div class="col"><input class="form-control form-control-sm" type="date" name="starts_on" required aria-label="From"></div>
                            <div class="col"><input class="form-control form-control-sm" type="date" name="ends_on" required aria-label="To"></div>
                        </div>
                        <select class="form-select form-select-sm mb-1" name="availability" aria-label="Availability"><option value="leave">On leave</option><option value="partial">Partly available</option><option value="unavailable">Unavailable</option></select>
                        <button class="btn btn-outline-primary btn-sm" type="submit">Save</button>
                    </form>
                </div></div>
            @endif
        </div>
    </div>
@endsection
