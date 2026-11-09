@php($u = auth()->user())
<ul class="nav nav-tabs mb-3">
    @if (\App\Support\AccessContext::tenantCan($u, 'billing.invoices.view'))
        <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.invoices.*') ? 'active' : '' }}" href="{{ route('practice.invoices.index') }}">Invoices</a></li>
    @endif
    @if (\App\Support\AccessContext::tenantCan($u, 'billing.fee_arrangements.view'))
        <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.fees.*') ? 'active' : '' }}" href="{{ route('practice.fees.index') }}">Fee arrangements</a></li>
    @endif
    @if (\App\Support\AccessContext::tenantCan($u, 'billing.retainer_plans.manage') || \App\Support\AccessContext::tenantCan($u, 'billing.retainer_work.manage') || \App\Support\AccessContext::tenantCan($u, 'billing.retainer_work.update_assigned'))
        <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.retainers.*') ? 'active' : '' }}" href="{{ route('practice.retainers.index') }}">Retainers</a></li>
    @endif
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.time.*') ? 'active' : '' }}" href="{{ route('practice.time.index') }}">Time and expenses</a></li>
    @if (\App\Support\AccessContext::tenantCan($u, 'billing.reports.view'))
        <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.reports') ? 'active' : '' }}" href="{{ route('practice.reports') }}">Reports</a></li>
    @endif
</ul>
