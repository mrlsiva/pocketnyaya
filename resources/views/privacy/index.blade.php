@extends('layouts.app')
@section('title', 'Privacy and my data')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <h1 class="h4 mb-0">Privacy and my data</h1>
        <a class="btn btn-outline-secondary btn-sm" href="{{ route('citizen.privacy.export') }}">Download my data</a>
    </div>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">What you've agreed to</h2>
                @foreach ($purposes as $purpose)
                    @php($granted = ($states[$purpose->id] ?? null) === 'granted')
                    <div class="d-flex flex-wrap justify-content-between gap-2 py-2 border-bottom">
                        <div class="flex-grow-1" style="min-width: 60%">
                            <div class="fw-semibold">{{ $purpose->name }} @if ($purpose->is_mandatory)<span class="badge text-bg-light">Needed for your account</span>@endif</div>
                            <div class="small text-body-secondary">{{ $purpose->description }}</div>
                        </div>
                        <div>
                            @if ($purpose->is_mandatory && $granted)
                                <span class="small">On</span>
                            @else
                                <form method="post" action="{{ route('citizen.privacy.consent', $purpose) }}">@csrf
                                    <input type="hidden" name="action" value="{{ $granted ? 'withdraw' : 'grant' }}">
                                    <button class="btn btn-sm {{ $granted ? 'btn-outline-secondary' : 'btn-outline-primary' }}" type="submit">{{ $granted ? 'Turn off' : 'Turn on' }}</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div></div>
            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Consent history</h2>
                <table class="table table-sm mb-0"><tbody>
                    @forelse ($history as $entry)
                        <tr><td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('d M Y H:i') }}</td><td>{{ $entry->name }}</td><td class="text-capitalize">{{ $entry->action }}</td></tr>
                    @empty
                        <tr><td class="text-body-secondary">Nothing recorded yet.</td></tr>
                    @endforelse
                </tbody></table>
            </div></div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Make a request</h2>
                <form method="post" action="{{ route('citizen.privacy.requests.store') }}">@csrf
                    <select class="form-select form-select-sm mb-2" name="request_type" aria-label="Type of request">
                        <option value="access">See what data you hold about me</option>
                        <option value="correction">Correct my data</option>
                        <option value="erasure">Erase my account and data</option>
                        <option value="grievance">Raise a complaint</option>
                        <option value="nomination">Nominate someone to act for me</option>
                    </select>
                    <textarea class="form-control form-control-sm mb-2" name="details" rows="3" placeholder="Anything we should know (optional)" aria-label="Details"></textarea>
                    <button class="btn btn-primary btn-sm" type="submit">Send request</button>
                </form>
                <p class="small text-body-secondary mt-2 mb-0">Erasing your account can't be undone. Some records may have to be kept, and we'll tell you which and why.</p>
            </div></div>
            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">My requests</h2>
                @forelse ($requests as $item)
                    <div class="d-flex justify-content-between small py-1 border-bottom">
                        <span class="text-capitalize">{{ $item->request_type }} · {{ str_replace('_', ' ', $item->status) }}</span>
                        @if ($item->status === 'submitted')
                            <form method="post" action="{{ route('citizen.privacy.requests.withdraw', $item) }}">@csrf<button class="btn btn-link btn-sm p-0" type="submit">Withdraw</button></form>
                        @endif
                    </div>
                    @foreach ($item->retained_items ?? [] as $kept)
                        <div class="small text-body-secondary ps-2">Kept: {{ str_replace('_', ' ', $kept['type']) }} ({{ str_replace('_', ' ', $kept['reason']) }})</div>
                    @endforeach
                @empty
                    <p class="small text-body-secondary mb-0">No requests yet.</p>
                @endforelse
            </div></div>
        </div>
    </div>
@endsection
