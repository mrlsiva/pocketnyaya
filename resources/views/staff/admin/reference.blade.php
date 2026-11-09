@extends('layouts.app')
@section('title', 'Reference data')

@section('content')
    <h1 class="h4 mb-3">Reference data</h1>
    <div class="row g-3">
        <div class="col-md-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body">
            <h2 class="h6">Practice areas</h2>
            <form class="d-flex gap-1 mb-2" method="post" action="{{ route('staff.admin.reference.store', 'practice-area') }}">@csrf<input class="form-control form-control-sm" name="name" placeholder="Name" required aria-label="Practice area name"><button class="btn btn-primary btn-sm" type="submit">Add</button></form>
            @foreach ($areas as $row)<div class="small">{{ $row->name }}</div>@endforeach
        </div></div></div>
        <div class="col-md-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body">
            <h2 class="h6">Languages</h2>
            <form class="mb-2" method="post" action="{{ route('staff.admin.reference.store', 'language') }}">@csrf
                <input class="form-control form-control-sm mb-1" name="code" placeholder="Code" required aria-label="Language code">
                <input class="form-control form-control-sm mb-1" name="name" placeholder="Name" required aria-label="Language name">
                <button class="btn btn-primary btn-sm" type="submit">Add</button></form>
            @foreach ($languages as $row)<div class="small">{{ $row->name }}</div>@endforeach
        </div></div></div>
        <div class="col-md-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body">
            <h2 class="h6">Cities</h2>
            <form class="mb-2" method="post" action="{{ route('staff.admin.reference.store', 'city') }}">@csrf
                <input class="form-control form-control-sm mb-1" name="name" placeholder="Name" required aria-label="City name">
                <select class="form-select form-select-sm mb-1" name="state_id" required aria-label="State">@foreach ($states as $state)<option value="{{ $state->id }}">{{ $state->name }}</option>@endforeach</select>
                <button class="btn btn-primary btn-sm" type="submit">Add</button></form>
            @foreach ($cities as $row)<div class="small">{{ $row->name }}</div>@endforeach
        </div></div></div>
        <div class="col-md-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body">
            <h2 class="h6">Courts</h2>
            <form class="mb-2" method="post" action="{{ route('staff.admin.reference.store', 'court') }}">@csrf
                <input class="form-control form-control-sm mb-1" name="name" placeholder="Name" required aria-label="Court name">
                <input class="form-control form-control-sm mb-1" name="court_type" placeholder="Type, such as district" required aria-label="Court type">
                <select class="form-select form-select-sm mb-1" name="state_id" required aria-label="State">@foreach ($states as $state)<option value="{{ $state->id }}">{{ $state->name }}</option>@endforeach</select>
                <button class="btn btn-primary btn-sm" type="submit">Add</button></form>
            @foreach ($courts as $row)<div class="small">{{ $row->name }}</div>@endforeach
        </div></div></div>
    </div>
@endsection
