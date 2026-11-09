@extends('layouts.app')
@section('title', $heading)

@section('content')
    <h1 class="h4 mb-3">{{ $heading }}</h1>
    @if ($nav) @include($nav) @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm"><div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Title</th><th>Category</th><th>Confidentiality</th><th>Added</th></tr></thead>
                    <tbody>
                        @forelse ($documents as $document)
                            <tr>
                                <td><a href="{{ route($showRoute, $document) }}">{{ $document->title }}</a></td>
                                <td class="text-capitalize">{{ $document->category }}</td>
                                <td class="text-capitalize">{{ $document->confidentiality }}</td>
                                <td class="text-nowrap">{{ $document->created_at->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-body-secondary">No documents yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div></div>
            <div class="mt-3">{{ $documents->links('pagination::bootstrap-5') }}</div>

            @isset($received)
                @if ($received->isNotEmpty())
                    <div class="card shadow-sm mt-3"><div class="card-body">
                        <h2 class="h6">Shared with me</h2>
                        <ul class="list-group list-group-flush">
                            @foreach ($received as $document)
                                <li class="list-group-item px-0"><a href="{{ route('citizen.vault.show', $document) }}">{{ $document->title }}</a></li>
                            @endforeach
                        </ul>
                    </div></div>
                @endif
            @endisset
        </div>

        @if ($canUpload)
            <div class="col-lg-4">
                <div class="card shadow-sm"><div class="card-body">
                    <h2 class="h6">Upload a document</h2>
                    <form method="post" action="{{ $uploadRoute }}" enctype="multipart/form-data">@csrf
                        <div class="mb-2"><label class="form-label small" for="file">File (PDF, Word, image or text, up to 25 MB)</label>
                            <input class="form-control form-control-sm" id="file" name="file" type="file" required></div>
                        <div class="mb-2"><label class="form-label small" for="title">Title</label>
                            <input class="form-control form-control-sm" id="title" name="title" value="{{ old('title') }}" required></div>
                        <div class="mb-2"><label class="form-label small" for="category">Category</label>
                            <select class="form-select form-select-sm" id="category" name="category">
                                @foreach (\App\Http\Requests\Documents\UploadDocumentRequest::CATEGORIES as $category)
                                    @if ($category !== 'verification')<option value="{{ $category }}">{{ ucfirst($category) }}</option>@endif
                                @endforeach
                            </select></div>
                        <div class="mb-2"><label class="form-label small" for="confidentiality">Confidentiality</label>
                            <select class="form-select form-select-sm" id="confidentiality" name="confidentiality">
                                <option value="normal">Normal</option><option value="confidential">Confidential</option>
                                @if ($allowPrivileged)<option value="privileged">Privileged</option>@endif
                            </select></div>
                        @if ($folders->isNotEmpty())
                            <div class="mb-2"><label class="form-label small" for="folder_id">Folder</label>
                                <select class="form-select form-select-sm" id="folder_id" name="folder_id"><option value="">None</option>
                                    @foreach ($folders as $folder)<option value="{{ $folder->id }}">{{ $folder->name }}</option>@endforeach
                                </select></div>
                        @endif
                        <button class="btn btn-primary btn-sm" type="submit">Upload</button>
                    </form>
                    <p class="small text-body-secondary mt-2 mb-0">Files are encrypted before they are stored.</p>
                </div></div>
            </div>
        @endif
    </div>
@endsection
