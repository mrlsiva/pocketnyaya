@extends('layouts.app')
@section('title', 'Practice closure')

@section('content')
    @php
        $me = auth()->id();
        $stage = fn (bool $done) => $done ? 'text-success' : 'text-body-secondary';
    @endphp
    <h1 class="h4 mb-1">Closing {{ $closure->tenant?->name }}</h1>
    <p class="small text-body-secondary">Stage: <span class="text-capitalize fw-semibold">{{ str_replace('_', ' ', $closure->status) }}</span>. Staff see the stages only, never the firm's records.</p>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm"><div class="card-body">
                <ol class="mb-0 small">
                    <li class="{{ $stage(true) }}">Requested by {{ $names[$closure->requested_by] ?? 'someone' }} on {{ $closure->requested_at?->format('d M Y') }}. Reason: {{ $closure->reason }}</li>
                    <li class="{{ $stage($closure->approved_at !== null) }}">Approved and suspended{{ $closure->approved_at ? ' by '.($names[$closure->approved_by] ?? 'someone').' on '.$closure->approved_at->format('d M Y') : '' }}</li>
                    <li class="{{ $stage($closure->holds_checked_at !== null) }}">Legal holds checked{{ $closure->holds_checked_at ? ': '.$closure->active_holds_at_check.' active at '.$closure->holds_checked_at->format('d M Y H:i') : '' }}. Active now: {{ $currentHolds }}</li>
                    <li class="{{ $stage($closure->last_exported_at !== null || $closure->export_waived_reason !== null) }}">
                        Export for {{ $closure->recipient?->name }}:
                        @if ($closure->export_waived_reason) waived ({{ $closure->export_waived_reason }})
                        @elseif ($closure->last_exported_at) downloaded {{ $closure->export_downloads }} time(s), last {{ $closure->last_exported_at->format('d M Y') }}
                        @elseif ($closure->export_expires_at) open until {{ $closure->export_expires_at->format('d M Y') }}, not downloaded yet
                        @else not opened yet @endif
                    </li>
                    <li class="{{ $stage($closure->retention_started_at !== null) }}">Retention{{ $closure->retention_started_at ? ' from '.$closure->retention_started_at->format('d M Y').'. The practice is closed and hidden, and every record is kept' : '' }}</li>
                    <li class="text-body-secondary">Final disposal: switched off.
                        @if ($closure->disposal_status === 'blocked_hold') A legal hold also blocks it.
                        @elseif ($closure->disposal_status === 'blocked_no_rule') No approved retention rule covers a closed practice yet.@endif</li>
                    <li class="{{ $stage(true) }}">Every step is recorded in the audit log.</li>
                </ol>
                @if ($closure->status === 'cancelled')
                    <div class="alert alert-secondary small mt-3 mb-0">Cancelled by {{ $names[$closure->cancelled_by] ?? 'someone' }}: {{ $closure->cancel_reason }}</div>
                @endif
            </div></div>
        </div>

        <div class="col-lg-5">
            @if ($closure->status === 'requested')
                <div class="card shadow-sm mb-3"><div class="card-body">
                    @if ($closure->requested_by === $me)
                        <p class="small mb-0">Waiting for a different Super Admin to approve.</p>
                    @else
                        <form method="post" action="{{ route('staff.admin.offboarding.act', $closure) }}">@csrf
                            <button class="btn btn-danger btn-sm" name="action" value="approve" type="submit">Approve and suspend the practice</button>
                        </form>
                    @endif
                </div></div>
            @endif
            @if (in_array($closure->status, ['suspended', 'export_open'], true))
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <form class="mb-2" method="post" action="{{ route('staff.admin.offboarding.act', $closure) }}">@csrf
                        <button class="btn btn-primary btn-sm" name="action" value="export" type="submit">{{ $closure->status === 'export_open' ? 'Extend the export window' : 'Open the export for the recipient' }}</button>
                    </form>
                    <form class="mb-2" method="post" action="{{ route('staff.admin.offboarding.act', $closure) }}">@csrf
                        <input class="form-control form-control-sm mb-1" name="reason" placeholder="Why no export is needed" aria-label="Reason">
                        <button class="btn btn-outline-secondary btn-sm" name="action" value="waive" type="submit">Waive the export</button>
                    </form>
                    <form method="post" action="{{ route('staff.admin.offboarding.act', $closure) }}">@csrf
                        <button class="btn btn-outline-danger btn-sm" name="action" value="retention" type="submit" @disabled($closure->last_exported_at === null && $closure->export_waived_reason === null)>Close the practice and start retention</button>
                    </form>
                </div></div>
            @endif
            @if ($closure->isOpen())
                <div class="card shadow-sm"><div class="card-body">
                    <form method="post" action="{{ route('staff.admin.offboarding.act', $closure) }}">@csrf
                        <input class="form-control form-control-sm mb-1" name="reason" placeholder="Reason for cancelling" required aria-label="Reason for cancelling">
                        <button class="btn btn-link btn-sm p-0" name="action" value="cancel" type="submit">Cancel the closure</button>
                    </form>
                </div></div>
            @endif
        </div>
    </div>
@endsection
