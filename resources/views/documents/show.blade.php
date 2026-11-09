@extends('layouts.app')
@section('title', $document->title)

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">{{ $document->title }}</h1>
            <div class="small text-body-secondary text-capitalize">{{ $document->category }} · {{ $document->confidentiality }}</div>
        </div>
        <div class="d-flex gap-2">
            @can('download', $document)
                <a class="btn btn-primary btn-sm" href="{{ route($context.'.download', $document) }}">Download</a>
            @endcan
            @can('delete', $document)
                <form method="post" action="{{ route($context.'.destroy', $document) }}">@csrf @method('delete')
                    <button class="btn btn-outline-danger btn-sm" type="submit">Remove</button></form>
            @endcan
        </div>
    </div>
    @if ($nav) @include($nav) @endif

    <div class="row g-3">
        <div class="col-lg-7"><div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Versions</h2>
            <ul class="list-group list-group-flush">
                @forelse ($versions as $version)
                    <li class="list-group-item px-0">
                        Version {{ $version->version_number }} · {{ number_format($version->size_bytes / 1024, 1) }} KB · {{ $version->created_at->format('d M Y H:i') }}
                        @if ($version->isShredded())<span class="badge text-bg-secondary">Permanently erased</span>@endif
                        @if ($version->change_note)<div class="small text-body-secondary">{{ $version->change_note }}</div>@endif
                    </li>
                @empty
                    <li class="list-group-item px-0 text-body-secondary">No file yet.</li>
                @endforelse
            </ul>
        </div></div></div>
        <div class="col-lg-5">
            @can('uploadVersion', $document)
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Upload a new version</h2>
                    <form method="post" action="{{ route($context.'.versions.store', $document) }}" enctype="multipart/form-data">@csrf
                        <input class="form-control form-control-sm mb-2" name="file" type="file" required aria-label="File">
                        <input class="form-control form-control-sm mb-2" name="change_note" placeholder="What changed? (optional)">
                        <button class="btn btn-primary btn-sm" type="submit">Upload</button>
                    </form>
                </div></div>
            @endcan
            @if ($recipients !== [])
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6">Share with your advocate</h2>
                    <form method="post" action="{{ route('citizen.vault.shares.store', $document) }}">@csrf
                        <select class="form-select form-select-sm mb-2" name="recipient_id" required aria-label="Recipient">@foreach ($recipients as $recipient)<option value="{{ $recipient->id }}">{{ $recipient->name }}</option>@endforeach</select>
                        <select class="form-select form-select-sm mb-2" name="permission" aria-label="Permission"><option value="view">Can view</option><option value="download">Can download</option></select>
                        <button class="btn btn-primary btn-sm" type="submit">Share</button>
                    </form>
                </div></div>
            @endif
            @if ($shares->isNotEmpty())
                <div class="card shadow-sm"><div class="card-body">
                    <h2 class="h6">Shared with</h2>
                    @foreach ($shares as $share)
                        <div class="d-flex justify-content-between small py-1">
                            <span>{{ $share->granteeUser?->name ?? 'A practice' }} ({{ $share->permission }})</span>
                            @can('revoke', $share)
                                <form method="post" action="{{ route($context.'.shares.revoke', $share) }}">@csrf @method('delete')<button class="btn btn-link btn-sm p-0" type="submit">Revoke</button></form>
                            @endcan
                        </div>
                    @endforeach
                </div></div>
            @endif
        </div>
    </div>
@endsection
