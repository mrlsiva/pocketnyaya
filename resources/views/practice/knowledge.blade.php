@extends('layouts.app')
@section('title', 'Knowledge vault')

@section('content')
    <h1 class="h4 mb-3">Knowledge vault</h1>
    @include('practice.nav')
    <div class="row g-3">
        <div class="col-lg-8">
            <form class="row g-2 mb-3" method="get">
                <div class="col"><input class="form-control form-control-sm" name="q" value="{{ $search }}" placeholder="Search titles, summaries and tags" aria-label="Search"></div>
                <div class="col-auto"><select class="form-select form-select-sm" name="type" aria-label="Type"><option value="">All types</option>
                    @foreach ($types as $option)<option value="{{ $option }}" @selected($type === $option)>{{ ucfirst($option) }}</option>@endforeach
                </select></div>
                <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Search</button></div>
            </form>
            @forelse ($items as $item)
                <div class="card shadow-sm mb-2"><div class="card-body py-2">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold">{{ $item->title }}</div>
                            <div class="small text-body-secondary text-capitalize">{{ $item->item_type }} · {{ $item->status }}</div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-start">
                            @if ($item->document_id)<a class="btn btn-outline-secondary btn-sm" href="{{ route('practice.knowledge.download', $item) }}">Download</a>@endif
                            @if ($canApprove && $item->status === 'draft')
                                <form method="post" action="{{ route('practice.knowledge.approve', $item) }}">@csrf<button class="btn btn-outline-primary btn-sm" type="submit">Approve</button></form>
                            @endif
                            @if ($canArchive && $item->status === 'approved')
                                <form method="post" action="{{ route('practice.knowledge.archive', $item) }}">@csrf<button class="btn btn-outline-secondary btn-sm" type="submit">Archive</button></form>
                            @endif
                        </div>
                    </div>
                    @if ($item->summary)<p class="small mb-1 mt-1">{{ $item->summary }}</p>@endif
                    @foreach ($item->tags ?? [] as $tag)<span class="badge text-bg-light">{{ $tag }}</span>@endforeach
                </div></div>
            @empty
                <p class="text-body-secondary">Nothing here yet.</p>
            @endforelse
            <div class="mt-3">{{ $items->links('pagination::bootstrap-5') }}</div>
        </div>
        @if ($canContribute)
            <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
                <h2 class="h6">Add an item</h2>
                <form method="post" action="{{ route('practice.knowledge.store') }}" enctype="multipart/form-data">@csrf
                    <select class="form-select form-select-sm mb-2" name="item_type" aria-label="Type">@foreach ($types as $option)<option value="{{ $option }}">{{ ucfirst($option) }}</option>@endforeach</select>
                    <input class="form-control form-control-sm mb-2" name="title" placeholder="Title" required aria-label="Title">
                    <select class="form-select form-select-sm mb-2" name="practice_area_id" aria-label="Practice area"><option value="">Practice area (optional)</option>@foreach ($areas as $area)<option value="{{ $area->id }}">{{ $area->name }}</option>@endforeach</select>
                    <select class="form-select form-select-sm mb-2" name="source_matter_id" aria-label="Source matter"><option value="">Source matter (optional)</option>@foreach ($matters as $matter)<option value="{{ $matter->id }}">{{ $matter->matter_number }}</option>@endforeach</select>
                    <textarea class="form-control form-control-sm mb-2" name="summary" rows="3" placeholder="Summary. Don't include client details." aria-label="Summary"></textarea>
                    <input class="form-control form-control-sm mb-2" name="tags" placeholder="Tags, separated by commas" aria-label="Tags">
                    <input class="form-control form-control-sm mb-2" name="file" type="file" aria-label="File">
                    <button class="btn btn-primary btn-sm" type="submit">Save draft</button>
                </form>
            </div></div></div>
        @endif
    </div>
@endsection
