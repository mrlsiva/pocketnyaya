@extends('layouts.app')
@section('title', $dispute->title)

@section('content')
    @php
        $open = $dispute->isOpen();
        $byId = $participants->keyBy('id');
        $parties = $participants->where('status', 'joined')->whereIn('participant_role', ['claimant', 'respondent']);
    @endphp
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">{{ $dispute->title }}</h1>
            <div class="small text-body-secondary">{{ $dispute->dispute_number }} · {{ str_replace('_', ' ', $dispute->category) }}</div>
        </div>
        <span class="badge text-bg-light align-self-start text-capitalize">{{ str_replace('_', ' ', $dispute->status) }}</span>
    </div>
    @if ($dispute->closure_reason)<div class="alert alert-secondary small">{{ $dispute->closure_reason }}</div>@endif
    @if ($dispute->status === 'settled_unsigned')
        <div class="alert alert-info small">All parties accepted the terms. This is an unsigned settlement agreement. It isn't a court order or decree, and nothing here guarantees that it can be enforced.</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <p class="mb-0" style="white-space: pre-line">{{ $dispute->description }}</p>
                @if ($dispute->claim_amount !== null)<div class="small mt-2">Amount in dispute: {{ \App\Support\Money::format($dispute->claim_amount) }}</div>@endif
            </div></div>

            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Messages</h2>
                @forelse ($messages as $message)
                    <div class="py-2 border-bottom">
                        <div class="small text-body-secondary">
                            {{ $message->sender_mediator_profile_id ? 'Mediator' : ($byId[$message->sender_participant_id]->name ?? 'Participant') }}
                            · {{ $message->created_at?->format('d M Y H:i') }}
                            @if ($message->visibility === 'mediator_private')<span class="badge text-bg-warning">Private with mediator</span>@endif
                            @if ($isMediator && $message->tone_check)<span class="badge text-bg-secondary">Tone flagged</span>@endif
                        </div>
                        <div style="white-space: pre-line">{{ $message->body }}</div>
                        @if ($message->document_id)<a class="small" href="{{ route('citizen.odr.documents.download', [$dispute, $message->document_id]) }}">Download file</a>@endif
                    </div>
                @empty
                    <p class="text-body-secondary small mb-0">No messages yet.</p>
                @endforelse
                @if ($open && $dispute->status !== 'draft')
                    <form class="mt-3" method="post" action="{{ route('citizen.odr.messages', $dispute) }}">@csrf
                        <textarea class="form-control form-control-sm mb-2" name="body" rows="2" required aria-label="Message"></textarea>
                        @if ($isMediator)
                            <select class="form-select form-select-sm mb-2" name="private_with" aria-label="Send to"><option value="">Everyone</option>
                                @foreach ($participants->where('status', 'joined') as $person)<option value="{{ $person->id }}">Private: {{ $person->name }}</option>@endforeach
                            </select>
                        @elseif ($messages->contains(fn ($m) => $m->visibility === 'mediator_private'))
                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="caucus" name="caucus" value="1"><label class="form-check-label small" for="caucus">Send privately to the mediator</label></div>
                        @endif
                        <button class="btn btn-primary btn-sm" type="submit">Send</button>
                    </form>
                @endif
            </div></div>

            @if ($settlement)
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Settlement agreement (unsigned), version {{ $settlement->version_number }}</h2>
                    <p class="small text-body-secondary">Accepting records your agreement to these terms. It isn't a digital signature, and this isn't a court order.</p>
                    <p style="white-space: pre-line">{{ $settlement->terms['text'] ?? '' }}</p>
                    <div class="small mb-2 text-capitalize">Status: {{ str_replace('_', ' ', $settlement->status) }}</div>
                    @if ($settlement->document_id)<a class="btn btn-outline-secondary btn-sm" href="{{ route('citizen.odr.documents.download', [$dispute, $settlement->document_id]) }}">Download unsigned PDF</a>@endif
                    @if ($settlement->status === 'under_review' && $me && $me->isParty() && $canAcceptSettlement && $settlement->acceptances->firstWhere('participant_id', $me->id)?->status === 'pending')
                        <form class="d-inline" method="post" action="{{ route('citizen.odr.settlements.respond', [$dispute, $settlement]) }}">@csrf
                            <button class="btn btn-primary btn-sm" name="decision" value="accept" type="submit">Accept terms</button>
                            <button class="btn btn-outline-danger btn-sm" name="decision" value="decline" type="submit">Decline</button>
                        </form>
                    @endif
                    @if ($isMediator && $settlement->status === 'under_review')
                        <form class="d-inline" method="post" action="{{ route('citizen.odr.settlements.void', [$dispute, $settlement]) }}">@csrf<button class="btn btn-outline-secondary btn-sm" type="submit">Void</button></form>
                    @endif
                </div></div>
            @endif

            @if ($isMediator && $open)
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Prepare a settlement</h2>
                    @if ($templates->isEmpty())
                        <p class="small text-body-secondary mb-0">No legally reviewed template exists for this category yet.</p>
                    @else
                        <form method="post" action="{{ route('citizen.odr.settlements.store', $dispute) }}">@csrf
                            <select class="form-select form-select-sm mb-2" name="template_id" aria-label="Template">@foreach ($templates as $template)<option value="{{ $template->id }}">{{ $template->title }} v{{ $template->version }}</option>@endforeach</select>
                            <textarea class="form-control form-control-sm mb-2" name="terms" rows="4" placeholder="The agreed terms" required aria-label="Terms"></textarea>
                            <button class="btn btn-primary btn-sm" type="submit">Generate unsigned agreement</button>
                        </form>
                    @endif
                </div></div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">People</h2>
                @foreach ($participants as $person)
                    <div class="d-flex justify-content-between small py-1">
                        <span>{{ $person->name }} <span class="text-body-secondary text-capitalize">({{ str_replace('_', ' ', $person->participant_role) }}{{ $person->status === 'invited' ? ', invited' : '' }})</span></span>
                        @if ($me && $open && $person->participant_role === $me->participant_role.'_counsel')
                            <form method="post" action="{{ route('citizen.odr.counsel.remove', [$dispute, $person]) }}">@csrf @method('delete')<button class="btn btn-link btn-sm p-0" type="submit">Remove</button></form>
                        @endif
                    </div>
                @endforeach
                @if ($isClaimant && in_array($dispute->status, ['awaiting_respondent', 'negotiation', 'mediation'], true) && $participants->where('participant_role', 'respondent')->isEmpty())
                    <form class="mt-2" method="post" action="{{ route('citizen.odr.respondent', $dispute) }}">@csrf
                        <div class="small fw-semibold mb-1">Invite the other party</div>
                        <input class="form-control form-control-sm mb-1" name="name" placeholder="Name" required aria-label="Name">
                        <input class="form-control form-control-sm mb-1" name="email" type="email" placeholder="Email" required aria-label="Email">
                        <input class="form-control form-control-sm mb-1" name="organization_name" placeholder="Organisation (optional)" aria-label="Organisation">
                        <button class="btn btn-primary btn-sm" type="submit">Send invitation</button>
                    </form>
                @endif
                @if ($me && $me->isParty() && $open && $dispute->status !== 'draft')
                    <form class="mt-2" method="post" action="{{ route('citizen.odr.counsel', $dispute) }}">@csrf
                        <div class="small fw-semibold mb-1">Invite your counsel</div>
                        <input class="form-control form-control-sm mb-1" name="name" placeholder="Name" required aria-label="Counsel name">
                        <input class="form-control form-control-sm mb-1" name="email" type="email" placeholder="Email" required aria-label="Counsel email">
                        <button class="btn btn-outline-secondary btn-sm" type="submit">Invite</button>
                    </form>
                @endif
            </div></div>

            @if ($offers->isNotEmpty() || ($me && $me->isParty() && $open && $parties->count() === 2))
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Offers</h2>
                    @foreach ($offers as $offer)
                        <div class="small py-1 border-bottom">
                            {{ $byId[$offer->offered_by_participant_id]->name ?? 'A party' }}:
                            @if ($offer->amount !== null){{ \App\Support\Money::format($offer->amount) }}.@endif {{ $offer->terms['text'] ?? '' }}
                            <span class="badge text-bg-light text-capitalize">{{ $offer->status }}</span>
                            @if ($offer->status === 'open' && $me && $me->isParty() && $offer->offered_by_participant_id !== $me->id && $open)
                                <form class="mt-1" method="post" action="{{ route('citizen.odr.offers.respond', [$dispute, $offer]) }}">@csrf
                                    <button class="btn btn-primary btn-sm" name="decision" value="accept" type="submit">Accept</button>
                                    <button class="btn btn-outline-secondary btn-sm" name="decision" value="reject" type="submit">Reject</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                    @if ($me && $me->isParty() && $open && $parties->count() === 2)
                        <form class="mt-2" method="post" action="{{ route('citizen.odr.offers', $dispute) }}">@csrf
                            <input class="form-control form-control-sm mb-1" name="amount" placeholder="Amount (optional)" aria-label="Amount">
                            <textarea class="form-control form-control-sm mb-1" name="terms" rows="2" placeholder="Terms" required aria-label="Terms"></textarea>
                            <button class="btn btn-primary btn-sm" type="submit">Make an offer</button>
                        </form>
                    @endif
                </div></div>
            @endif

            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Sessions</h2>
                @forelse ($sessions as $session)
                    <div class="small py-1 border-bottom">{{ $session->scheduled_at->format('d M Y H:i') }} · {{ str_replace('_', ' ', $session->mode) }} · {{ $session->duration_minutes }} min · {{ str_replace('_', ' ', $session->status) }}
                        @if ($isMediator && $session->status === 'scheduled')
                            <form class="mt-1 d-flex gap-1" method="post" action="{{ route('citizen.odr.sessions.update', [$dispute, $session]) }}">@csrf @method('put')
                                <button class="btn btn-outline-secondary btn-sm" name="status" value="held" type="submit">Held</button>
                                <button class="btn btn-outline-secondary btn-sm" name="status" value="cancelled" type="submit">Cancel</button>
                                <button class="btn btn-outline-secondary btn-sm" name="status" value="no_show" type="submit">No-show</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="small text-body-secondary mb-0">None scheduled. Video calls aren't available yet, so please arrange the link separately.</p>
                @endforelse
                @if ($isMediator && $open)
                    <form class="mt-2" method="post" action="{{ route('citizen.odr.sessions.store', $dispute) }}">@csrf
                        <input class="form-control form-control-sm mb-1" name="scheduled_at" type="datetime-local" required aria-label="When">
                        <input class="form-control form-control-sm mb-1" name="duration_minutes" type="number" min="5" value="60" required aria-label="Minutes">
                        <select class="form-select form-select-sm mb-1" name="mode" aria-label="Mode"><option value="video">Video</option><option value="audio">Audio</option><option value="in_person">In person</option></select>
                        <button class="btn btn-primary btn-sm" type="submit">Schedule</button>
                    </form>
                @endif
            </div></div>

            @if ($open && $dispute->status !== 'draft')
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Add evidence</h2>
                    <form method="post" action="{{ route('citizen.odr.evidence', $dispute) }}" enctype="multipart/form-data">@csrf
                        <input class="form-control form-control-sm mb-1" name="title" placeholder="What is this?" required aria-label="Title">
                        <input class="form-control form-control-sm mb-1" name="file" type="file" required aria-label="File">
                        <button class="btn btn-outline-secondary btn-sm" type="submit">Upload</button>
                    </form>
                    <p class="small text-body-secondary mt-2 mb-0">Evidence can't be deleted after it's added.</p>
                </div></div>
            @endif

            @if ($isClaimant && $open && $dispute->status !== 'settlement_pending_acceptance')
                <form method="post" action="{{ route('citizen.odr.withdraw', $dispute) }}">@csrf<button class="btn btn-outline-danger btn-sm" type="submit">Withdraw this dispute</button></form>
            @endif
            @if ($isMediator && $open)
                <form class="mt-2" method="post" action="{{ route('citizen.odr.close', $dispute) }}">@csrf
                    <input class="form-control form-control-sm mb-1" name="closure_reason" placeholder="Why it's closing" required aria-label="Reason">
                    <button class="btn btn-outline-danger btn-sm" type="submit">Close without settlement</button>
                </form>
            @endif
        </div>
    </div>
@endsection
