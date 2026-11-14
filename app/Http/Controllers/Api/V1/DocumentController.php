<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\DocumentShare;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentVault;
use App\Services\Documents\ShareService;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
        $this->checkPrivilegedAllowed($request);

        return response()->json($this->payload($documents->create($this->user($request), $request->file('file'), $this->details($request), ['scope' => 'tenant', 'tenant_id' => $matter->tenant_id, 'matter_id' => $matter->id])), 201);
    }

    /** A general practice document, not tied to a matter or client. */
    public function storePractice(UploadDocumentRequest $request, DocumentService $documents): JsonResponse
    {
        $tenant = (string) AccessContext::currentTenantId();
        Gate::authorize('createGeneral', [Document::class, $tenant]);
        $this->checkPrivilegedAllowed($request);

        return response()->json($this->payload($documents->create($this->user($request), $request->file('file'), $this->details($request), ['scope' => 'tenant', 'tenant_id' => $tenant])), 201);
    }

    public function storeForClient(UploadDocumentRequest $request, Client $client, DocumentService $documents): JsonResponse
    {
        Gate::authorize('createForClient', [Document::class, $client]);
        $this->checkPrivilegedAllowed($request);

        return response()->json($this->payload($documents->create($this->user($request), $request->file('file'), $this->details($request), ['scope' => 'tenant', 'tenant_id' => $client->tenant_id, 'client_id' => $client->id])), 201);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('view', $document);
        $user = $this->user($request);
        app(DocumentVault::class)->log($document, 'view', $user);

        return response()->json($this->payload($document) + [
            'versions' => $document->versions()->orderByDesc('version_number')->get(['version_number', 'size_bytes', 'change_note', 'created_at']),
            // Active shares for those who may manage the document, and who the owner may share a vault file with (DA-28).
            'shares' => Gate::allows('update', $document) ? $document->shares()->active()->get(['id', 'grantee_user_id', 'permission', 'expires_at']) : [],
            'share_recipients' => $document->scope === 'personal' && $document->owner_user_id === $user->id ? app(ShareService::class)->vaultRecipients($user) : [],
        ]);
    }

    public function storeVersion(Request $request, Document $document, DocumentVault $vault): JsonResponse
    {
        Gate::authorize('uploadVersion', $document);
        $data = $request->validate(['file' => UploadDocumentRequest::fileRules(), 'change_note' => ['nullable', 'string', 'max:500']]);
        $vault->addVersion($document, $request->file('file'), $this->user($request), $data['change_note'] ?? null);

        return response()->json($this->payload($document) + ['latest_version' => $document->latestVersion()?->version_number], 201);
    }

    /** DA-28: shared only with someone the owner already works with. The service enforces that. */
    public function share(Request $request, Document $document, ShareService $shares): JsonResponse
    {
        Gate::authorize('view', $document);
        $data = $request->validate(['recipient_id' => ['required', 'uuid'], 'permission' => ['required', Rule::in(['view', 'download'])], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $recipient = User::query()->find($data['recipient_id']);
        abort_if($recipient === null, 422, 'That person is not available.');
        $shares->shareWithUser($document, $this->user($request), $recipient, $data['permission'], $data['expires_at'] ?? null);

        return response()->json(['message' => 'Document shared.'], 201);
    }

    public function revokeShare(Request $request, DocumentShare $share, DocumentVault $vault): JsonResponse
    {
        Gate::authorize('revoke', $share);
        $share->forceFill(['revoked_at' => now()])->save();
        $vault->log($share->document, 'revoke', $this->user($request));

        return response()->json(['message' => 'Share revoked.']);
    }

    public function vaultFolders(Request $request): JsonResponse
    {
        return response()->json(['data' => DocumentFolder::query()->where(['scope' => 'personal', 'owner_user_id' => $this->user($request)->id])->orderBy('name')->get(['id', 'name', 'parent_id'])]);
    }

    public function storeVaultFolder(Request $request): JsonResponse
    {
        Gate::authorize('createPersonal', DocumentFolder::class);

        return $this->folder($request, 'personal', null);
    }

    public function practiceFolders(): JsonResponse
    {
        return response()->json(['data' => DocumentFolder::query()->where(['scope' => 'tenant', 'tenant_id' => AccessContext::currentTenantId()])->orderBy('name')->get(['id', 'name', 'parent_id'])]);
    }

    public function storePracticeFolder(Request $request): JsonResponse
    {
        $tenant = (string) AccessContext::currentTenantId();
        Gate::authorize('createInTenant', [DocumentFolder::class, $tenant]);

        return $this->folder($request, 'tenant', $tenant);
    }

    private function folder(Request $request, string $scope, ?string $tenant): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $folder = new DocumentFolder($data);
        $folder->forceFill(['scope' => $scope, 'tenant_id' => $tenant, 'owner_user_id' => $this->user($request)->id])->save();

        return response()->json($folder->only(['id', 'name']), 201);
    }

    /** Marking a document privileged needs the right to see privileged material (D4.3), as on the web. */
    private function checkPrivilegedAllowed(UploadDocumentRequest $request): void
    {
        if ($request->input('confidentiality') === 'privileged') {
            abort_unless(AccessContext::tenantCan($this->user($request), 'tenant_documents.view_privileged'), 403);
        }
    }

    public function download(Request $request, Document $document, DocumentVault $vault): StreamedResponse
    {
        Gate::authorize('download', $document);

        return $vault->download($document, $document->latestVersion(), $this->user($request));
    }

    public function destroy(Request $request, Document $document, DocumentVault $vault): JsonResponse
    {
        Gate::authorize('delete', $document);
        abort_if(LegalHold::coversDocument($document), 422, 'A legal hold covers this document.');
        $vault->log($document, 'delete', $this->user($request));
        $document->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function payload(Document $document): array
    {
        return $document->only(['id', 'title', 'category', 'confidentiality', 'scope', 'folder_id', 'matter_id', 'client_id', 'created_at']);
    }

    /** @return array<string, mixed> */
    private function details(UploadDocumentRequest $request): array
    {
        return $request->safe()->only(['title', 'category', 'confidentiality', 'folder_id']);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
