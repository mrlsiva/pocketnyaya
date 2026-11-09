@extends('layouts.app')
@section('title', 'Privacy requests')

@section('content')
    <h1 class="h4 mb-3">Privacy requests</h1>
    @include('staff.privacy.nav')
    @forelse ($requests as $item)
        <div class="card shadow-sm mb-2"><div class="card-body">
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <div>
                    <span class="fw-semibold text-capitalize">{{ $item->request_type }}</span>
                    <span class="badge text-bg-light text-capitalize">{{ str_replace('_', ' ', $item->status) }}</span>
                    <div class="small text-body-secondary">{{ $item->user?->name }} · {{ $item->user?->email }} · due {{ $item->due_at->format('d M Y') }}</div>
                    @if ($item->details)<div class="small mt-1">{{ $item->details }}</div>@endif
                </div>
                @if (\App\Support\AccessContext::staffCan(auth()->user(), 'dpdp.requests.process'))
                    <form class="d-flex flex-wrap gap-1 align-items-start" method="post" action="{{ route('staff.privacy.requests.process', $item) }}">@csrf
                        <input class="form-control form-control-sm w-auto" name="decision_reason" placeholder="Reason" aria-label="Reason">
                        @if ($item->status === 'submitted')<button class="btn btn-outline-primary btn-sm" name="action" value="verify_identity" type="submit">Identity verified</button>@endif
                        @if ($item->status === 'identity_verification')<button class="btn btn-outline-primary btn-sm" name="action" value="start_review" type="submit">Start review</button>@endif
                        @if ($item->status === 'in_review')<button class="btn btn-outline-primary btn-sm" name="action" value="approve" type="submit">Approve</button>@endif
                        @if ($item->status === 'approved')<button class="btn btn-primary btn-sm" name="action" value="complete" type="submit">{{ $item->request_type === 'erasure' ? 'Carry out erasure' : 'Mark complete' }}</button>@endif
                        @if (in_array($item->status, ['submitted', 'identity_verification', 'in_review'], true))<button class="btn btn-outline-danger btn-sm" name="action" value="reject" type="submit">Reject</button>@endif
                    </form>
                @endif
            </div>
        </div></div>
    @empty
        <p class="text-body-secondary">No open requests.</p>
    @endforelse
    <div class="mt-3">{{ $requests->links('pagination::bootstrap-5') }}</div>
@endsection
