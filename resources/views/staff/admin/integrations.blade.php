@extends('layouts.app')
@section('title', 'Integrations')

@section('content')
    <h1 class="h4 mb-1">Integrations</h1>
    <p class="small text-body-secondary">Every outside service is switched off until its API access, cost, data location (India) and legal requirements are checked and approved. Nothing is sent outside the platform until then. Credentials go in the server's .env file, never here.</p>

    <div class="card shadow-sm mb-3"><div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Service</th><th>Used for</th><th>Status</th></tr></thead>
            <tbody>
                @foreach ($services as $service)
                    <tr>
                        <td>{{ $service['name'] }}</td>
                        <td class="small">{{ $service['purpose'] }}</td>
                        <td>
                            @if ($service['active'])<span class="badge text-bg-success">On ({{ $service['driver'] }})</span>
                            @elseif ($service['driver'] !== 'none')<span class="badge text-bg-warning">"{{ $service['driver'] }}" is set but not available, so it stays off</span>
                            @else<span class="badge text-bg-secondary">Switched off</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div></div>

    <div class="card shadow-sm"><div class="card-body">
        <h2 class="h6">Personal data masking (step 19)</h2>
        <p class="small text-body-secondary">This runs on this server only. It masks emails, phone numbers, Aadhaar, PAN, GSTIN, CNR, UPI IDs, IFSC codes and account numbers. It does not recognise names or addresses, so a person must still review masked text. Use synthetic text only. The preview isn't stored.</p>
        <form method="post" action="{{ route('staff.admin.integrations.mask') }}">@csrf
            <textarea class="form-control form-control-sm mb-2" name="sample" rows="4" maxlength="5000" aria-label="Text to mask">{{ $sample }}</textarea>
            <button class="btn btn-outline-primary btn-sm" type="submit">Preview masking</button>
        </form>
        @if ($masked)
            <pre class="small bg-body-tertiary p-2 mt-3 mb-1" style="white-space: pre-wrap">{{ $masked['text'] }}</pre>
            <div class="small text-body-secondary">@forelse ($masked['counts'] as $label => $count){{ $label }}: {{ $count }} @empty Nothing found. @endforelse</div>
        @endif
    </div></div>
@endsection
