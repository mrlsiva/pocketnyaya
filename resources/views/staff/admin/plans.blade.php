@extends('layouts.app')
@section('title', 'Subscription plans')

@section('content')
    <h1 class="h4 mb-3">Subscription plans</h1>
    <div class="row g-3">
        <div class="col-lg-7"><div class="card shadow-sm"><div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Plan</th><th>Period</th><th class="text-end">Price</th><th></th></tr></thead>
                <tbody>
                    @forelse ($plans as $plan)
                        <tr class="{{ $plan->is_active ? '' : 'text-body-secondary' }}">
                            <td>{{ $plan->name }}<div class="small text-body-secondary">{{ $plan->code }}</div></td>
                            <td class="text-capitalize">{{ $plan->billing_period }}</td>
                            <td class="text-end">{{ $plan->price === null ? 'Not set' : \App\Support\Money::format($plan->price) }}</td>
                            <td class="text-end"><form method="post" action="{{ route('staff.admin.plans.toggle', $plan->id) }}">@csrf<button class="btn btn-link btn-sm p-0" type="submit">{{ $plan->is_active ? 'Turn off' : 'Turn on' }}</button></form></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-body-secondary">No plans yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div></div>
        <div class="col-lg-5"><div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Add a plan</h2>
            <form method="post" action="{{ route('staff.admin.plans.store') }}">@csrf
                <input class="form-control form-control-sm mb-2" name="code" placeholder="plan_code" required aria-label="Code">
                <input class="form-control form-control-sm mb-2" name="name" placeholder="Name" required aria-label="Name">
                <select class="form-select form-select-sm mb-2" name="billing_period" aria-label="Period"><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select>
                <input class="form-control form-control-sm mb-2" name="price" placeholder="Price" aria-label="Price">
                <button class="btn btn-primary btn-sm" type="submit">Add</button>
            </form>
        </div></div></div>
    </div>
@endsection
