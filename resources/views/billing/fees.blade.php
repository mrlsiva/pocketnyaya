@extends('layouts.app')
@section('title', 'Fee arrangements')

@section('content')
    @php($money = \App\Support\Money::class)
    <h1 class="h4 mb-3">Fee arrangements</h1>
    @include('practice.nav')
    @include('billing.nav')
    <div class="row g-3">
        <div class="col-lg-8">
            @forelse ($arrangements as $arrangement)
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold">{{ $arrangement->client?->name }}</div>
                            <div class="small text-body-secondary">
                                <span class="text-capitalize">{{ str_replace('_', ' ', $arrangement->arrangement_type) }}</span>
                                @if ($arrangement->matter) · {{ $arrangement->matter->matter_number }}@endif
                                @if ($arrangement->plan) · {{ $arrangement->plan->name }}@endif
                                · <span class="text-capitalize">{{ $arrangement->status }}</span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="text-nowrap">{{ $money::format($arrangement->hourly_rate ?? $arrangement->retainer_amount ?? $arrangement->plan?->monthly_fee ?? 0) }}</div>
                            @if ($canManage && $arrangement->status !== 'ended')
                                <form method="post" action="{{ route('practice.fees.end', $arrangement) }}">@csrf<button class="btn btn-link btn-sm p-0" type="submit">End</button></form>
                            @endif
                        </div>
                    </div>

                    @if ($arrangement->scheduleItems->isNotEmpty())
                        <table class="table table-sm small mt-2 mb-0">
                            <thead><tr><th>Scheduled fee</th><th>Event</th><th class="text-end">Amount</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($arrangement->scheduleItems as $item)
                                    <tr>
                                        <td>{{ $item->description }}</td>
                                        <td class="text-capitalize">{{ $item->event_type }}</td>
                                        <td class="text-end text-nowrap">{{ $money::format($item->amount) }}</td>
                                        <td class="text-end">
                                            @if ($canManage)
                                                <form method="post" action="{{ route('practice.fees.items.destroy', $item) }}">@csrf @method('delete')<button class="btn btn-link btn-sm p-0" type="submit">Remove</button></form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if ($canManage && $arrangement->status !== 'ended')
                        <form class="row g-2 mt-1" method="post" action="{{ route('practice.fees.items.store', $arrangement) }}">@csrf
                            <div class="col-md-3"><select class="form-select form-select-sm" name="event_type" aria-label="Event">
                                <option value="appearance">Appearance</option><option value="drafting">Drafting</option><option value="consultation">Consultation</option><option value="filing">Filing</option><option value="conference">Conference</option><option value="other">Other</option>
                            </select></div>
                            <div class="col-md-4"><input class="form-control form-control-sm" name="description" placeholder="Scheduled fee, e.g. One appearance" required aria-label="Description"></div>
                            <div class="col-md-3"><input class="form-control form-control-sm" name="amount" placeholder="Amount" required aria-label="Amount"></div>
                            <div class="col-md-2"><button class="btn btn-outline-primary btn-sm" type="submit">Add fee</button></div>
                        </form>
                    @endif
                </div></div>
            @empty
                <div class="card shadow-sm"><div class="card-body text-body-secondary">No fee arrangements yet.</div></div>
            @endforelse
        </div>
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
                    @if ($plans->isNotEmpty())
                        <select class="form-select form-select-sm mb-2" name="retainer_plan_id" aria-label="Retainer plan"><option value="">No retainer plan</option>
                            @foreach ($plans as $plan)<option value="{{ $plan->id }}">{{ $plan->name }} · {{ $money::format($plan->monthly_fee) }}</option>@endforeach
                        </select>
                    @endif
                    <input class="form-control form-control-sm mb-2" name="starts_on" type="date" value="{{ now()->toDateString() }}" required aria-label="Starts on">
                    <button class="btn btn-primary btn-sm" type="submit">Add</button>
                </form>
                <p class="small text-body-secondary mt-2 mb-0">Fees are private to the practice and never shown publicly.</p>
            </div></div></div>
        @endif
    </div>
@endsection
