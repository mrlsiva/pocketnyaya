<ul class="nav nav-tabs mb-3">
    @foreach ([['staff.privacy.requests', 'Requests', 'dpdp.requests.view'], ['staff.privacy.retention', 'Retention rules', 'dpdp.retention_rules.draft'], ['staff.privacy.holds', 'Legal holds', 'dpdp.legal_holds.manage'], ['staff.privacy.breaches', 'Breaches', 'dpdp.breaches.manage'], ['staff.privacy.policies', 'Disclaimers and purposes', 'disclaimers.manage']] as [$name, $label, $permission])
        @if (\App\Support\AccessContext::staffCan(auth()->user(), $permission))
            <li class="nav-item"><a class="nav-link {{ request()->routeIs($name) ? 'active' : '' }}" href="{{ route($name) }}">{{ $label }}</a></li>
        @endif
    @endforeach
</ul>
