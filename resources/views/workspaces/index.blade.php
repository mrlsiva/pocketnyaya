@extends('layouts.app')
@section('title', 'Choose a workspace')

@section('content')
    <h1 class="h4 mb-1">Choose a workspace</h1>
    <p class="text-body-secondary">Each workspace is kept separate. Nothing is combined between them.</p>
    <div class="row g-3">
        @foreach ($workspaces as $workspace)
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm h-100"><div class="card-body d-flex flex-column">
                    <h2 class="h6">{{ $workspace['name'] }}</h2>
                    <p class="small text-body-secondary flex-grow-1">{{ implode(', ', $workspace['roles']) ?: 'No roles yet' }}</p>
                    @if ($workspace['kind'] === 'tenant')
                        <form method="post" action="{{ route('tenant.switch') }}">@csrf
                            <input type="hidden" name="tenant_id" value="{{ $workspace['tenant_id'] }}">
                            <button class="btn btn-primary btn-sm" type="submit">Open</button>
                        </form>
                    @elseif ($workspace['kind'] === 'staff')
                        <div><a class="btn btn-primary btn-sm" href="{{ route('staff.dashboard') }}">Open</a></div>
                    @else
                        <div><a class="btn btn-primary btn-sm" href="{{ route('citizen.dashboard') }}">Open</a></div>
                    @endif
                </div></div>
            </div>
        @endforeach
    </div>
@endsection
