@extends('layouts.app')
@section('title', $matter->matter_number)

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">{{ $matter->title }}</h1>
            <div class="small text-body-secondary">{{ $matter->matter_number }} · <a href="{{ route('practice.clients.show', $matter->client) }}">{{ $matter->client->name }}</a> ·
                <span class="text-capitalize">{{ str_replace('_', ' ', $matter->status) }}</span></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @can('update', $matter)<a class="btn btn-outline-secondary btn-sm" href="{{ route('practice.matters.edit', $matter) }}">Edit</a>@endcan
            @can('close', $matter)
                <form method="post" action="{{ route('practice.matters.close', $matter) }}">@csrf<button class="btn btn-outline-secondary btn-sm" type="submit">Close</button></form>
            @endcan
            @can('reopen', $matter)
                <form method="post" action="{{ route('practice.matters.reopen', $matter) }}">@csrf<button class="btn btn-outline-secondary btn-sm" type="submit">Reopen</button></form>
            @endcan
            @can('archive', $matter)
                <form method="post" action="{{ route('practice.matters.archive', $matter) }}">@csrf<button class="btn btn-outline-secondary btn-sm" type="submit">Archive</button></form>
            @endcan
        </div>
    </div>
    @include('practice.nav')

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Hearings</h2>
                <ul class="list-group list-group-flush mb-3">
                    @forelse ($hearings as $hearing)
                        <li class="list-group-item px-0">
                            <div class="fw-semibold">{{ $hearing->hearing_date->format('d M Y') }} {{ $hearing->scheduled_time ? substr($hearing->scheduled_time, 0, 5) : '' }}
                                <span class="badge text-bg-light text-capitalize">{{ str_replace('_', ' ', $hearing->status) }}</span></div>
                            <div class="small">{{ $hearing->court?->name }} {{ $hearing->court_hall ? "· Hall {$hearing->court_hall}" : '' }} {{ $hearing->purpose ? "· {$hearing->purpose}" : '' }}</div>
                            @if ($hearing->outcome_summary)<div class="small text-body-secondary">{{ $hearing->outcome_summary }}</div>@endif
                            @if ($hearing->status === 'scheduled')
                                @can('recordOutcome', $hearing)
                                    <details class="mt-1"><summary class="small">Record outcome</summary>
                                        <form class="row g-2 mt-1" method="post" action="{{ route('practice.hearings.outcome', $hearing) }}">@csrf
                                            <div class="col-sm-4"><select class="form-select form-select-sm" name="status" aria-label="Outcome">
                                                @foreach (['attended', 'adjourned', 'not_reached', 'disposed', 'cancelled'] as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
                                            </select></div>
                                            <div class="col-sm-4"><input class="form-control form-control-sm" name="next_hearing_date" type="date" aria-label="Next hearing date"></div>
                                            <div class="col-12"><textarea class="form-control form-control-sm" name="outcome_summary" rows="2" placeholder="Summary"></textarea></div>
                                            <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Save</button></div>
                                        </form>
                                    </details>
                                @endcan
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-body-secondary">No hearings yet.</li>
                    @endforelse
                </ul>
                @can('create', [\App\Models\Hearing::class, $matter])
                    <form class="row g-2" method="post" action="{{ route('practice.hearings.store', $matter) }}">@csrf
                        <div class="col-sm-5"><select class="form-select form-select-sm" name="court_id" required aria-label="Court">
                            <option value="">Court…</option>
                            @foreach ($courts as $court)<option value="{{ $court->id }}" @selected($matter->court_id === $court->id)>{{ $court->name }}</option>@endforeach
                        </select></div>
                        <div class="col-sm-4"><input class="form-control form-control-sm" name="hearing_date" type="date" required aria-label="Hearing date"></div>
                        <div class="col-sm-3"><input class="form-control form-control-sm" name="scheduled_time" type="time" aria-label="Time"></div>
                        <div class="col-sm-9"><input class="form-control form-control-sm" name="purpose" placeholder="Purpose"></div>
                        <div class="col-sm-3"><button class="btn btn-primary btn-sm w-100" type="submit">Add hearing</button></div>
                    </form>
                @endcan
            </div></div>

            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Documents</h2>
                <ul class="list-group list-group-flush mb-2">
                    @forelse ($documents as $document)
                        <li class="list-group-item px-0"><a href="{{ route('practice.documents.show', $document) }}">{{ $document->title }}</a>
                            <span class="small text-body-secondary text-capitalize">{{ $document->category }}</span></li>
                    @empty
                        <li class="list-group-item px-0 text-body-secondary">No documents you can see.</li>
                    @endforelse
                </ul>
                @can('createForMatter', [\App\Models\Document::class, $matter])
                    <form class="row g-2" method="post" action="{{ route('practice.matters.documents.store', $matter) }}" enctype="multipart/form-data">@csrf
                        <div class="col-12"><input class="form-control form-control-sm" name="file" type="file" required aria-label="File"></div>
                        <div class="col-7"><input class="form-control form-control-sm" name="title" placeholder="Title" required></div>
                        <div class="col-5"><select class="form-select form-select-sm" name="category" aria-label="Category">
                            @foreach (['pleading', 'order', 'evidence', 'notice', 'contract', 'other'] as $category)<option value="{{ $category }}">{{ ucfirst($category) }}</option>@endforeach
                        </select></div>
                        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Upload</button></div>
                    </form>
                @endcan
            </div></div>

            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Notes</h2>
                @forelse ($notes as $note)
                    <div class="border-bottom py-2">
                        <div class="small text-body-secondary d-flex justify-content-between">
                            <span>{{ $note->author?->name }} · {{ $note->created_at->format('d M Y H:i') }} · <span class="text-capitalize">{{ $note->visibility }}</span></span>
                            @can('changeNote', [\App\Models\Matter::class, $note])
                                <form method="post" action="{{ route('practice.notes.destroy', $note) }}">@csrf @method('delete')<button class="btn btn-link btn-sm p-0" type="submit">Remove</button></form>
                            @endcan
                        </div>
                        <div style="white-space: pre-line">{{ $note->body }}</div>
                    </div>
                @empty
                    <p class="text-body-secondary small">No notes you can see.</p>
                @endforelse
                @if (\App\Support\AccessContext::tenantCan(auth()->user(), 'matters.notes.create') && $matter->status !== 'archived')
                    <form class="mt-3" method="post" action="{{ route('practice.matters.notes.store', $matter) }}">@csrf
                        <textarea class="form-control form-control-sm mb-2" name="body" rows="2" required aria-label="New note"></textarea>
                        <div class="d-flex gap-2">
                            <select class="form-select form-select-sm w-auto" name="visibility" aria-label="Who can see this note">
                                <option value="team">Team</option><option value="private">Only me</option><option value="partners">Partners</option>
                            </select>
                            <button class="btn btn-primary btn-sm" type="submit">Add note</button>
                        </div>
                    </form>
                @endif
            </div></div>
        </div>

        <div class="col-lg-5">
            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Team</h2>
                <ul class="list-group list-group-flush mb-2">
                    @forelse ($assignments as $assignment)
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span>{{ $assignment->name }} <span class="small text-body-secondary text-capitalize">{{ str_replace('_', ' ', $assignment->role_on_matter) }}</span></span>
                            @can('allocate', $matter)
                                <form method="post" action="{{ route('practice.matters.unassign', [$matter, $assignment->id]) }}">@csrf @method('delete')<button class="btn btn-link btn-sm p-0" type="submit">Remove</button></form>
                            @endcan
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-body-secondary">Not allocated yet.</li>
                    @endforelse
                </ul>
                @can('allocate', $matter)
                    <form class="row g-2" method="post" action="{{ route('practice.matters.assign', $matter) }}">@csrf
                        <div class="col-7"><select class="form-select form-select-sm" name="membership_id" required aria-label="Team member">
                            <option value="">Person…</option>
                            @foreach ($members as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                        </select></div>
                        <div class="col-5"><select class="form-select form-select-sm" name="role_on_matter" aria-label="Role on matter">
                            <option value="lead">Lead</option><option value="associate">Associate</option><option value="junior">Junior</option><option value="clerk">Clerk</option>
                        </select></div>
                        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Add to team</button></div>
                    </form>
                @endcan
            </div></div>

            <div class="card shadow-sm mb-3"><div class="card-body">
                <h2 class="h6">Parties</h2>
                <ul class="list-group list-group-flush mb-2">
                    @forelse ($matter->parties as $party)
                        <li class="list-group-item px-0">{{ $party->name }} <span class="small text-body-secondary text-capitalize">{{ $party->party_role }}</span></li>
                    @empty
                        <li class="list-group-item px-0 text-body-secondary">No parties yet.</li>
                    @endforelse
                </ul>
                @can('manageParties', $matter)
                    <form class="row g-2" method="post" action="{{ route('practice.matters.parties.store', $matter) }}">@csrf
                        <div class="col-7"><input class="form-control form-control-sm" name="name" placeholder="Party name" required></div>
                        <div class="col-5"><select class="form-select form-select-sm" name="party_role" aria-label="Party role">
                            @foreach (['petitioner', 'respondent', 'appellant', 'plaintiff', 'defendant', 'complainant', 'accused', 'other'] as $role)<option value="{{ $role }}">{{ ucfirst($role) }}</option>@endforeach
                        </select></div>
                        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Add party</button></div>
                    </form>
                @endcan
            </div></div>

            <div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Tasks</h2>
                <ul class="list-group list-group-flush mb-2">
                    @forelse ($tasks as $task)
                        <li class="list-group-item px-0">
                            {{ $task->title }} <span class="badge text-bg-light text-capitalize">{{ str_replace('_', ' ', $task->status) }}</span>
                            <div class="small text-body-secondary">{{ $task->assignee?->user?->name ?? 'Unassigned' }} {{ $task->due_at ? '· due '.$task->due_at->format('d M Y') : '' }}</div>
                            @can('updateTaskStatus', [\App\Models\Matter::class, $task])
                                @if ($task->status !== 'done')
                                    <form method="post" action="{{ route('practice.tasks.status', $task) }}">@csrf @method('put')
                                        <input type="hidden" name="status" value="done"><button class="btn btn-link btn-sm p-0" type="submit">Mark done</button></form>
                                @endif
                            @endcan
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-body-secondary">No tasks yet.</li>
                    @endforelse
                </ul>
                @can('manageTasks', $matter)
                    <form class="row g-2" method="post" action="{{ route('practice.matters.tasks.store', $matter) }}">@csrf
                        <div class="col-12"><input class="form-control form-control-sm" name="title" placeholder="Task" required></div>
                        <div class="col-7"><select class="form-select form-select-sm" name="assigned_to_membership_id" aria-label="Assign to">
                            <option value="">Unassigned</option>
                            @foreach ($members as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                        </select></div>
                        <div class="col-5"><input class="form-control form-control-sm" name="due_at" type="date" aria-label="Due date"></div>
                        <input type="hidden" name="priority" value="normal">
                        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Add task</button></div>
                    </form>
                @endcan
            </div></div>
        </div>
    </div>
@endsection
