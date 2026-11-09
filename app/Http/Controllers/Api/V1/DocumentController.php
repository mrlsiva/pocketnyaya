<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Models\Document;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentVault;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Files only ever leave through an authorised download. There are no public URLs. */
class DocumentController extends Controller
{
    public function vault(Request $request): JsonResponse
    {
        $page = Document::query()->where(['scope' => 'personal', 'owner_user_id' => $this->user($request)->id])->latest()->paginate(25);

        return response()->json($page->through(fn (Document $d) => $this->payload($d)));
    }

    public function storeVault(UploadDocumentRequest $request, DocumentService $documents): JsonResponse
    {
        Gate::authorize('createPersonal', Document::class);

        return response()->json($this->payload($documents->create($this->user($request), $request->file('file'), $this->details($request), ['scope' => 'personal'])), 201);
    }

    public function practice(Request $request): JsonResponse
    {
        $page = Document::query()->visibleTo($this->user($request))->where('scope', 'tenant')->where('tenant_id', AccessContext::currentTenantId())->latest()->paginate(25);

        return response()->json($page->through(fn (Document $d) => $this->payload($d)));
    }

    public function storeForMatter(UploadDocumentRequest $request, Matter $matter, DocumentService $documents): JsonResponse
    {
        Gate::authorize('createForMatter', [Document::class, $matter]);

        return response()->json($this->payload($documents->create($this->user($request), $request->file('file'), $this->details($request), ['scope' => 'tenant', 'tenant_id' => $matter->tenant_id, 'matter_id' => $matter->id])), 201);
    }

    public function show(Document $document): JsonResponse
    {
        Gate::authorize('view', $document);

        return response()->json($this->payload($document) + [
            'versions' => $document->versions()->orderByDesc('version_number')->get(['version_number', 'size_bytes', 'created_at']),
        ]);
    }

    public function download(Request $request, Document $document, DocumentVault $vault): StreamedResponse
    {
        Gate::authorize('download', $document);

        return $vault->download($document, $document->latestVersion(), $this->user($request));
    }

    public function destroy(Request $request, Document $document, DocumentVault $vault): JsonResponse
    {
        Gate::authorize('delete', $document);
        abort_if(LegalHold::isActive('document', $document->id), 422, 'A legal hold covers this document.');
        $vault->log($document, 'delete', $this->user($request));
        $document->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function payload(Document $document): array
    {
        return $document->only(['id', 'title', 'category', 'confidentiality', 'scope', 'matter_id', 'client_id', 'created_at']);
    }

    /** @return array<string, mixed> */
    private function details(UploadDocumentRequest $request): array
    {
        return $request->safe()->only(['title', 'category', 'confidentiality']);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
