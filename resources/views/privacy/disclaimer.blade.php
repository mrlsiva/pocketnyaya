@extends('layouts.app')
@section('title', $disclaimer->title)

@section('content')
    <div class="row justify-content-center"><div class="col-lg-8"><div class="card shadow-sm"><div class="card-body">
        <h1 class="h5">{{ $disclaimer->title }}</h1>
        <p style="white-space: pre-line">{{ $disclaimer->body }}</p>
        <div class="small text-body-secondary">Version {{ $disclaimer->version }}, effective {{ $disclaimer->effective_from->format('d M Y') }}</div>
    </div></div></div></div>
@endsection
