@extends('layouts.app')
@section('title', $tenant->name)

@section('content')
    <h1 class="h4 mb-3">{{ $tenant->name }}</h1>
    @include('practice.nav')
    <div class="row g-3">
        <div class="col-sm-6 col-lg-3"><div class="card shadow-sm"><div class="card-body">
            <div class="small text-body-secondary">Matters you can see</div><div class="h3 mb-0">{{ $stats['matters'] }}</div>
        </div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="card shadow-sm"><div class="card-body">
            <div class="small text-body-secondary">Clients you can see</div><div class="h3 mb-0">{{ $stats['clients'] }}</div>
        </div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="card shadow-sm"><div class="card-body">
            <div class="small text-body-secondary">My hearings, next 14 days</div><div class="h3 mb-0">{{ $stats['hearings'] }}</div>
        </div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="card shadow-sm"><div class="card-body">
            <div class="small text-body-secondary">My open tasks</div><div class="h3 mb-0">{{ $stats['tasks'] }}</div>
        </div></div></div>
    </div>
@endsection
