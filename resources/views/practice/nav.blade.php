@php($u = auth()->user())
<ul class="nav nav-pills flex-wrap gap-1 mb-4">
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.dashboard') ? 'active' : '' }}" href="{{ route('practice.dashboard') }}">Overview</a></li>
    @if (\App\Support\AccessContext::tenantCan($u, 'matters.view_all') || \App\Support\AccessContext::tenantCan($u, 'matters.view_team') || \App\Support\AccessContext::tenantCan($u, 'matters.view_assigned'))
        <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.matters.*') ? 'active' : '' }}" href="{{ route('practice.matters.index') }}">Matters</a></li>
    @endif
    @if (\App\Support\AccessContext::tenantCan($u, 'clients.view_all') || \App\Support\AccessContext::tenantCan($u, 'clients.view_assigned'))
        <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.clients.*') ? 'active' : '' }}" href="{{ route('practice.clients.index') }}">Clients</a></li>
    @endif
    @if (\App\Support\AccessContext::tenantCan($u, 'tenant_documents.view'))
        <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.documents.*') ? 'active' : '' }}" href="{{ route('practice.documents.index') }}">Documents</a></li>
    @endif
    @if (\App\Support\AccessContext::tenantCan($u, 'consultations.inbox.view_own') || \App\Support\AccessContext::tenantCan($u, 'consultations.inbox.view_all'))
        <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.consultations.*') ? 'active' : '' }}" href="{{ route('practice.consultations.index') }}">Consultations</a></li>
    @endif
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.invoices.*', 'practice.fees.*', 'practice.time.*', 'practice.reports') ? 'active' : '' }}" href="{{ \App\Support\AccessContext::tenantCan($u, 'billing.invoices.view') ? route('practice.invoices.index') : route('practice.time.index') }}">Billing</a></li>
    @if (\App\Support\AccessContext::tenantCan($u, 'knowledge.view'))
        <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.knowledge.*') ? 'active' : '' }}" href="{{ route('practice.knowledge.index') }}">Knowledge</a></li>
    @endif
    @if (\App\Support\AccessContext::tenantCan($u, 'tenant.audit.view'))
        <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.audit') ? 'active' : '' }}" href="{{ route('practice.audit') }}">Audit log</a></li>
    @endif
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.team.*') ? 'active' : '' }}" href="{{ route('practice.team.index') }}">Team</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.settings.*') ? 'active' : '' }}" href="{{ route('practice.settings.index') }}">Settings</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.diary.*') ? 'active' : '' }}" href="{{ route('practice.diary.index') }}">My diary</a></li>
    @if (\App\Support\AccessContext::tenantCan($u, 'calendar.firm.view'))
        <li class="nav-item"><a class="nav-link {{ request()->routeIs('practice.calendar') ? 'active' : '' }}" href="{{ route('practice.calendar') }}">Firm calendar</a></li>
    @endif
</ul>
