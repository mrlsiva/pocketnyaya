@extends('layouts.app')
@section('title', 'Fee arrangements')

@section('content')
    <h1 class="h4 mb-3">Fee arrangements</h1>
    @include('practice.nav')
    @include('billing.nav')
    <div class="row g-3">
        <div class="col-lg-8"><div class="card shadow-sm"><div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Client</th><th>Type</th><th class="text-end">Rate or amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($arrangements as $arrangement)
                        <tr>
                            <td>{{ $arrangement->client?->name }}<div class="small text-body-secondary">{{ $arrangement->matter?->matter_number }}</div></td>
                            <td class="text-capitalize">{{ str_replace('_', ' ', $arrangement->arrangement_type) }}</td>
                            <td class="text-end text-nowrap">{{ \App\Support\Money::format($arrangement->hourly_rate ?? $arrangement->retainer_amount ?? 0) }}</td>
                            <td class="text-capitalize">{{ $arrangement->status }}</td>
                            <td class="text-end">
                                @if ($canManage && $arrangement->status !== 'ended')
                                    <form method="post" action="{{ route('practice.fees.end', $arrangement) }}">@csrf<button class="btn btn-link btn-sm p-0" type="submit">End</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-body-secondary">No fee arrangements yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div></div>
        @if ($canManage)
            <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Add an arrangement</h2>
                <form method="post" action="{{ route('practice.fees.store') }}">@csrf
                    <select class="form-select form-select-sm mb-2" name="client_id" required aria-label="Client"><option value="">Client…</option>
                        @foreach ($clients as $client)<option value="{{ $client->id }}">{{ $client->name }}</option>@endforeach
                    </select>
                    <select class="form-select form-select-sm mb-2" name="arrangement_type" aria-label="Type">
                        <option value="hourly">Hourly</option><option value="monthly_retainer">Monthly retainer</option><option value="lump_sum">Lump sum</option><option value="event_based">Event based</option><option value="creator_retainer">Creator retainer</option>
                    </select>
                    <input class="form-control form-control-sm mb-2" name="hourly_rate" placeholder="Hourly rate" aria-label="Hourly rate">
                    <input class="form-control form-control-sm mb-2" name="retainer_amount" placeholder="Retainer or lump sum" aria-label="Retainer or lump sum">
                    <input class="form-control form-control-sm mb-2" name="starts_on" type="date" value="{{ now()->toDateString() }}" required aria-label="Starts on">
                    <button class="btn btn-primary btn-sm" type="submit">Add</button>
                </form>
            </div></div></div>
        @endif
    </div>
@endsection
