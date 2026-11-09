@extends('layouts.app')
@section('title', 'Time and expenses')

@section('content')
    <h1 class="h4 mb-3">Time and expenses</h1>
    @include('practice.nav')
    @include('billing.nav')
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Time</h2>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Date</th><th>Who</th><th>Matter</th><th>Work</th><th class="text-end">Minutes</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($time as $entry)
                            <tr>
                                <td class="text-nowrap">{{ $entry->work_date->format('d M') }}</td>
                                <td>{{ $entry->membership?->user?->name }}</td>
                                <td class="text-nowrap">{{ $entry->matter?->matter_number }}</td>
                                <td>{{ $entry->description }}</td>
                                <td class="text-end">{{ $entry->minutes }}</td>
                                <td class="text-capitalize">{{ $entry->status }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($entry->status === 'draft')
                                        @if ($entry->membership_id === $me)
                                            <form class="d-inline" method="post" action="{{ route('practice.time.destroy', $entry) }}">@csrf @method('delete')<button class="btn btn-link btn-sm p-0" type="submit">Remove</button></form>
                                        @endif
                                        @if (\App\Support\AccessContext::tenantCan($user, 'billing.time.approve_all') || \App\Support\AccessContext::tenantCan($user, 'billing.time.approve_supervisees'))
                                            <form class="d-inline" method="post" action="{{ route('practice.time.approve', $entry) }}">@csrf<button class="btn btn-link btn-sm p-0" type="submit">Approve</button></form>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-body-secondary">No time logged yet.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div></div>
            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Expenses</h2>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Date</th><th>Matter</th><th>Category</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        @forelse ($expenses as $expense)
                            <tr>
                                <td class="text-nowrap">{{ $expense->expense_date->format('d M') }}</td>
                                <td class="text-nowrap">{{ $expense->matter?->matter_number }}</td>
                                <td>{{ $expense->category }}</td>
                                <td class="text-end text-nowrap">{{ \App\Support\Money::format($expense->amount) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-body-secondary">No expenses yet.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div></div>
        </div>
        <div class="col-lg-4">
            @if ($canLogTime)
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Log time</h2>
                    <form method="post" action="{{ route('practice.time.store') }}">@csrf
                        <select class="form-select form-select-sm mb-2" name="matter_id" required aria-label="Matter"><option value="">Matter…</option>
                            @foreach ($matters as $matter)<option value="{{ $matter->id }}">{{ $matter->matter_number }}: {{ $matter->title }}</option>@endforeach
                        </select>
                        <div class="row g-2 mb-2">
                            <div class="col-7"><input class="form-control form-control-sm" name="work_date" type="date" value="{{ now()->toDateString() }}" required aria-label="Date"></div>
                            <div class="col-5"><input class="form-control form-control-sm" name="minutes" type="number" min="1" placeholder="Minutes" required aria-label="Minutes"></div>
                        </div>
                        <input class="form-control form-control-sm mb-2" name="description" placeholder="What did you do?" required aria-label="Work done">
                        <button class="btn btn-primary btn-sm" type="submit">Log</button>
                    </form>
                </div></div>
            @endif
            @if ($canLogExpense)
                <div class="card shadow-sm"><div class="card-body">
                    <h2 class="h6">Log an expense</h2>
                    <form method="post" action="{{ route('practice.expenses.store') }}">@csrf
                        <select class="form-select form-select-sm mb-2" name="matter_id" required aria-label="Matter"><option value="">Matter…</option>
                            @foreach ($matters as $matter)<option value="{{ $matter->id }}">{{ $matter->matter_number }}: {{ $matter->title }}</option>@endforeach
                        </select>
                        <div class="row g-2 mb-2">
                            <div class="col-7"><input class="form-control form-control-sm" name="expense_date" type="date" value="{{ now()->toDateString() }}" required aria-label="Date"></div>
                            <div class="col-5"><input class="form-control form-control-sm" name="amount" placeholder="Amount" required aria-label="Amount"></div>
                        </div>
                        <input class="form-control form-control-sm mb-2" name="category" placeholder="For example, Court fee" required aria-label="Category">
                        <button class="btn btn-primary btn-sm" type="submit">Log</button>
                    </form>
                </div></div>
            @endif
        </div>
    </div>
@endsection
