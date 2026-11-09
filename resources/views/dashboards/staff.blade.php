@extends('layouts.app')
@section('title', 'Platform administration')

@section('content')
    <h1 class="h4 mb-3">Platform administration</h1>
    <p class="text-body-secondary">Staff have no access to firm or personal content.</p>
    <div class="row g-3">
        @if (\App\Support\AccessContext::staffCan($user, 'verification.advocates.review'))
            <div class="col-md-6 col-lg-4"><div class="card shadow-sm h-100"><div class="card-body d-flex flex-column">
                <h2 class="h6">Verification queue</h2>
                <p class="small text-body-secondary flex-grow-1">Review advocate and mediator applications.</p>
                <div><a class="btn btn-primary btn-sm" href="{{ route('staff.verification.index') }}">Open queue</a></div>
            </div></div></div>
        @endif
        @if (\App\Support\AccessContext::staffCan($user, 'tax.platform_rules.draft'))
            <div class="col-md-6 col-lg-4"><div class="card shadow-sm h-100"><div class="card-body d-flex flex-column">
                <h2 class="h6">Tax rules</h2>
                <p class="small text-body-secondary flex-grow-1">Draft tax rules and record a qualified accountant's approval.</p>
                <div><a class="btn btn-primary btn-sm" href="{{ route('staff.tax.index') }}">Open tax rules</a></div>
            </div></div></div>
        @endif
        @if (\App\Support\AccessContext::staffCan($user, 'emergency.triage'))
            <div class="col-md-6 col-lg-4"><div class="card shadow-sm h-100"><div class="card-body d-flex flex-column">
                <h2 class="h6">Urgent requests</h2>
                <p class="small text-body-secondary flex-grow-1">Triage urgent help requests by priority.</p>
                <div><a class="btn btn-primary btn-sm" href="{{ route('staff.emergency.index') }}">Open queue</a></div>
            </div></div></div>
        @endif
        @foreach ([
            ['platform.tenants.view_metadata', 'Practices', 'See practices and suspend them if needed.', 'staff.admin.tenants'],
            ['platform.users.view_metadata', 'Accounts', 'See accounts and suspend them if needed.', 'staff.admin.users'],
            ['platform.tenants.close', 'Practice closures', 'Close a practice in stages, with export and retention. Nothing is deleted.', 'staff.admin.offboarding.index'],
            ['platform.superadmin.propose', 'Platform staff', 'Super Admins and Compliance Officers. New Super Admins need a second approval.', 'staff.admin.team'],
            ['platform.settings.manage', 'Platform settings', 'Non-secret settings for the platform.', 'staff.admin.settings'],
            ['platform.subscription_plans.manage', 'Subscription plans', 'Plans offered to practices.', 'staff.admin.plans'],
            ['platform.reference_data.manage', 'Reference data', 'Practice areas, languages, cities and courts.', 'staff.admin.reference'],
            ['moderation.queue.view', 'Moderation', 'Review flagged posts and reports. Authors are never shown.', 'staff.moderation.index'],
            ['dpdp.requests.view', 'Privacy requests', 'Access, correction and erasure requests.', 'staff.privacy.requests'],
            ['dpdp.retention_rules.record_approval', 'Retention rules', "Record counsel's approval of retention rules.", 'staff.privacy.retention'],
            ['disclaimers.manage', 'Compliance policies', 'Disclaimers, consent purposes, holds and breaches.', 'staff.privacy.policies'],
            ['platform.audit.view', 'Audit log', 'Who did what across the platform.', 'staff.audit'],
            ['odr.screening.review', 'ODR intake', 'Screen new disputes and propose mediators.', 'staff.odr.index'],
            ['odr.templates.manage', 'Settlement templates', 'Manage templates and record their legal review.', 'staff.odr.templates'],
        ] as [$permission, $cardTitle, $text, $link])
            @if (\App\Support\AccessContext::staffCan($user, $permission))
                <div class="col-md-6 col-lg-4"><div class="card shadow-sm h-100"><div class="card-body d-flex flex-column">
                    <h2 class="h6">{{ $cardTitle }}</h2>
                    <p class="small text-body-secondary flex-grow-1">{{ $text }}</p>
                    <div><a class="btn btn-primary btn-sm" href="{{ route($link) }}">Open</a></div>
                </div></div></div>
            @endif
        @endforeach
    </div>
@endsection
