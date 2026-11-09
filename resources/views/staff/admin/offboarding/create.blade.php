@extends('layouts.app')
@section('title', 'Close a practice')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-7">
        <h1 class="h4 mb-3">Close {{ $tenant->name }}</h1>
        <div class="alert alert-warning small">
            Closing happens in stages, and nothing is deleted. A different Super Admin must approve this request. On approval the practice is suspended at once.
            The firm then downloads its export, and the data is kept for the retention period. Final disposal stays switched off until retention rules are approved.
            Members' personal accounts and their other practices are not affected.
        </div>
        <div class="card shadow-sm"><div class="card-body">
            <form method="post" action="{{ route('staff.admin.offboarding.store', $tenant) }}">@csrf
                <div class="mb-3"><label class="form-label" for="reason">Reason, and who asked for it</label>
                    <textarea class="form-control" id="reason" name="reason" rows="3" required>{{ old('reason') }}</textarea></div>
                <div class="mb-3"><label class="form-label" for="recipient_id">Who receives the firm's export</label>
                    @if ($recipients->isEmpty())
                        <div class="alert alert-secondary small mb-0">This practice has no active owner, Managing Partner or Independent Lawyer, so nobody can receive the export. Get legal advice before closing it.</div>
                    @else
                        <select class="form-select" id="recipient_id" name="recipient_id" required>
                            @foreach ($recipients as $person)<option value="{{ $person->id }}">{{ $person->name }} ({{ $person->email }})</option>@endforeach
                        </select>
                    @endif
                </div>
                <button class="btn btn-danger" type="submit" @disabled($recipients->isEmpty())>Request closure</button>
                <a class="btn btn-link" href="{{ route('staff.admin.tenants') }}">Back</a>
            </form>
        </div></div>
    </div></div>
@endsection
