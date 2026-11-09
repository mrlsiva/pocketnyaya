@extends('layouts.app')
@section('title', 'Retainers')

@section('content')
    @php($money = \App\Support\Money::class)
    <h1 class="h4 mb-3">Retainers</h1>
    @include('practice.nav')
    @include('billing.nav')
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Retainer work</h2>
                <div class="table-responsive">
                    <table class="table align-middle small mb-0">
                        <thead><tr><th>Work</th><th>Client</th><th>Due</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse ($items as $item)
                                <tr>
                                    <td>{{ $item->title }}<div class="text-body-secondary text-capitalize">{{ str_replace('_', ' ', $item->work_type) }}@if ($item->matter) · {{ $item->matter->matter_number }}@endif</div></td>
                                    <td>{{ $item->arrangement?->client?->name }}</td>
                                    <td class="text-nowrap">{{ $item->due_at?->format('d M Y') }}</td>
                                    <td>
                                        <form class="d-flex gap-1" method="post" action="{{ route('practice.retainers.work.update', $item) }}">@csrf @method('put')
                                            <select class="form-select form-select-sm" name="status" aria-label="Status">
                                                @foreach (['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done'] as $value => $label)
                                                    <option value="{{ $value }}" @selected($item->status === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <button class="btn btn-outline-secondary btn-sm" type="submit">Save</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-body-secondary">No retainer work yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div></div>

            @if ($plans->isNotEmpty())
                <div class="card shadow-sm"><div class="card-body">
                    <h2 class="h6">Retainer plans</h2>
                    @foreach ($plans as $plan)
                        <div class="d-flex justify-content-between gap-2 py-2 border-bottom small">
                            <div>
                                <span class="fw-semibold">{{ $plan->name }}</span> · {{ $money::format($plan->monthly_fee) }} a month
                                @unless ($plan->is_active)<span class="badge text-bg-secondary">Retired</span>@endunless
                                @if ($plan->description)<div class="text-body-secondary">{{ $plan->description }}</div>@endif
                                @if (! empty($plan->scope_items))<ul class="mb-0">@foreach ($plan->scope_items as $scope)<li>{{ $scope }}</li>@endforeach</ul>@endif
                            </div>
                            @if ($canManagePlans)
                                <form method="post" action="{{ route('practice.retainers.plans.toggle', $plan) }}">@csrf<button class="btn btn-link btn-sm p-0" type="submit">{{ $plan->is_active ? 'Retire' : 'Reactivate' }}</button></form>
                            @endif
                        </div>
                    @endforeach
                </div></div>
            @endif
        </div>

        <div class="col-lg-4">
            @if ($canManageWork)
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Add retainer work</h2>
                    @if ($arrangements->isEmpty())
                        <p class="small text-body-secondary mb-0">Add a monthly or creator retainer under Fee arrangements first.</p>
                    @else
                        <form method="post" action="{{ route('practice.retainers.work.store') }}">@csrf
                            <select class="form-select form-select-sm mb-2" name="fee_arrangement_id" required aria-label="Retainer">
                                @foreach ($arrangements as $arrangement)<option value="{{ $arrangement->id }}">{{ $arrangement->client?->name }} · {{ str_replace('_', ' ', $arrangement->arrangement_type) }}</option>@endforeach
                            </select>
                            <select class="form-select form-select-sm mb-2" name="work_type" aria-label="Work type">
                                @foreach ($workTypes as $type)<option value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>@endforeach
                            </select>
                            <input class="form-control form-control-sm mb-2" name="title" placeholder="What needs doing" required aria-label="Title">
                            <input class="form-control form-control-sm mb-2" name="due_at" type="date" aria-label="Due date">
                            <button class="btn btn-primary btn-sm" type="submit">Add</button>
                        </form>
                    @endif
                </div></div>
            @endif

            @if ($canManagePlans)
                <div class="card shadow-sm"><div class="card-body">
                    <h2 class="h6">Add a retainer plan</h2>
                    <form method="post" action="{{ route('practice.retainers.plans.store') }}">@csrf
                        <input class="form-control form-control-sm mb-2" name="name" placeholder="Plan name" required aria-label="Plan name">
                        <input class="form-control form-control-sm mb-2" name="monthly_fee" placeholder="Monthly fee" required aria-label="Monthly fee">
                        <textarea class="form-control form-control-sm mb-2" name="scope_items" rows="3" placeholder="What it covers, one item per line" aria-label="Scope"></textarea>
                        <textarea class="form-control form-control-sm mb-2" name="description" rows="2" placeholder="Notes (optional)" aria-label="Notes"></textarea>
                        <button class="btn btn-primary btn-sm" type="submit">Add plan</button>
                    </form>
                    <p class="small text-body-secondary mt-2 mb-0">Plans are private templates for your own clients. They are never published, ranked or sold as packages.</p>
                </div></div>
            @endif
        </div>
    </div>
@endsection
