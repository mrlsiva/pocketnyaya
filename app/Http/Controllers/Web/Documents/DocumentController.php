<?php

namespace App\Http\Controllers\Web\Documents;

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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Practice documents and the personal vault share these actions. Which documents
 * a user reaches comes only from Document::visibleTo and DocumentPolicy.
 */
class DocumentController extends Controller
{
    /** Tenant documents in the current practice. */
    public function practiceIndex(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('documents.index', [
            'heading' => 'Documents',
            'documents' => Document::query()->visibleTo($user)->where('scope', 'tenant')->where('tenant_id', AccessContext::currentTenantId())
                ->latest()->paginate(25),
            'uploadRoute' => route('practice.documents.store'),
            'showRoute' => 'practice.documents.show',
            'nav' => 'practice.nav',
            'canUpload' => Gate::allows('createGeneral', [Document::class, (string) AccessContext::currentTenantId()]),
            'folders' => DocumentFolder::query()->where(['scope' => 'tenant', 'tenant_id' => AccessContext::currentTenantId()])->orderBy('name')->get(),
            'allowPrivileged' => AccessContext::tenantCan($user, 'tenant_documents.view_privileged') && AccessContext::tenantCan($user, 'matters.view_all'),
        ]);
    }

    public function practiceStore(UploadDocumentRequest $request, DocumentService $documents): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = (string) AccessContext::currentTenantId();
        Gate::authorize('createGeneral', [Document::class, $tenant]);
        $this->checkPrivilegedAllowed($request, $user);

        $document = $documents->create($user, $request->file('file'), $this->details($request), ['scope' => 'tenant', 'tenant_id' => $tenant]);

        return redirect()->route('practice.documents.show', $document)->with('status', 'Document uploaded and encrypted.');
    }

    public function storeForMatter(UploadDocumentRequest $request, Matter $matter, DocumentService $documents): RedirectResponse
    {
        Gate::authorize('createForMatter', [Document::class, $matter]);
        /** @var User $user */
        $user = $request->user();
        $this->checkPrivilegedAllowed($request, $user);

        $documents->create($user, $request->file('file'), $this->details($request), ['scope' => 'tenant', 'tenant_id' => $matter->tenant_id, 'matter_id' => $matter->id]);

        return back()->with('status', 'Document uploaded and encrypted.');
    }

    public function storeForClient(UploadDocumentRequest $request, Client $client, DocumentService $documents): RedirectResponse
    {
        Gate::authorize('createForClient', [Document::class, $client]);
        /** @var User $user */
        $user = $request->user();
        $this->checkPrivilegedAllowed($request, $user);

        $documents->create($user, $request->file('file'), $this->details($request), ['scope' => 'tenant', 'tenant_id' => $client->tenant_id, 'client_id' => $client->id]);

        return back()->with('status', 'Document uploaded and encrypted.');
    }

    /** The personal vault, plus documents shared with this user by name. */
    public function vaultIndex(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('documents.index', [
            'heading' => 'My document vault',
            'documents' => Document::query()->where('scope', 'personal')->where('owner_user_id', $user->id)->latest()->paginate(25),
            'received' => Document::query()->whereHas('shares', fn ($s) => $s->active()->where('grantee_user_id', $user->id))->latest()->get(),
            'uploadRoute' => route('citizen.vault.store'),
            'showRoute' => 'citizen.vault.show',
            'nav' => null,
            'canUpload' => Gate::allows('createPersonal', Document::class),
            'folders' => DocumentFolder::query()->where(['scope' => 'personal', 'owner_user_id' => $user->id])->orderBy('name')->get(),
            'allowPrivileged' => false,
        ]);
    }

    public function vaultStore(UploadDocumentRequest $request, DocumentService $documents): RedirectResponse
    {
        Gate::authorize('createPersonal', Document::class);
        /** @var User $user */
        $user = $request->user();

        $document = $documents->create($user, $request->file('file'), $this->details($request), ['scope' => 'personal']);

        return redirect()->route('citizen.vault.show', $document)->with('status', 'Document uploaded and encrypted.');
    }

    public function show(Request $request, Document $document, DocumentVault $vault): View
    {
        Gate::authorize('view', $document);
        /** @var User $user */
        $user = $request->user();
        $vault->log($document, 'view', $user);

        return view('documents.show', [
            'document' => $document,
            'versions' => $document->versions()->with('key')->orderByDesc('version_number')->get(),
            'context' => $request->route()?->getName() === 'citizen.vault.show' ? 'citizen.vault' : 'practice.documents',
            'nav' => $request->route()?->getName() === 'citizen.vault.show' ? null : 'practice.nav',
            'shares' => Gate::allows('update', $document) ? $document->shares()->active()->with('granteeUser')->get() : collect(),
            'recipients' => $document->scope === 'personal' && $document->owner_user_id === $user->id ? app(ShareService::class)->vaultRecipients($user) : [],
        ]);
    }

    public function download(Request $request, Document $document, DocumentVault $vault): StreamedResponse
    {
        Gate::authorize('download', $document);
        /** @var User $user */
        $user = $request->user();

        return $vault->download($document, $document->latestVersion(), $user);
    }

    public function storeVersion(Request $request, Document $document, DocumentVault $vault): RedirectResponse
    {
        Gate::authorize('uploadVersion', $document);
        $data = $request->validate(['file' => UploadDocumentRequest::fileRules(), 'change_note' => ['nullable', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();
        $vault->addVersion($document, $request->file('file'), $user, $data['change_note'] ?? null);

        return back()->with('status', 'New version uploaded and encrypted.');
    }

    public function destroy(Request $request, Document $document, DocumentVault $vault): RedirectResponse
    {
        Gate::authorize('delete', $document);
        abort_if(LegalHold::isActive('document', $document->id), 422, 'A legal hold covers this document, so it cannot be removed.');
        /** @var User $user */
        $user = $request->user();
        $vault->log($document, 'delete', $user);
        $document->delete();

        return redirect()->route($request->route()?->getName() === 'citizen.vault.destroy' ? 'citizen.vault.index' : 'practice.documents.index')->with('status', 'Document removed.');
    }

    /** DA-28: shared only with someone the owner already works with. */
    public function share(Request $request, Document $document, ShareService $shares): RedirectResponse
    {
        Gate::authorize('view', $document);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['recipient_id' => ['required', 'uuid'], 'permission' => ['required', Rule::in(['view', 'download'])], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $recipient = User::query()->find($data['recipient_id']);
        if ($recipient === null) {
            return back()->withErrors(['recipient' => 'That person is not available.']);
        }
        $shares->shareWithUser($document, $user, $recipient, $data['permission'], $data['expires_at'] ?? null);

        return back()->with('status', 'Document shared.');
    }

    public function revokeShare(Request $request, DocumentShare $share, DocumentVault $vault): RedirectResponse
    {
        Gate::authorize('revoke', $share);
        /** @var User $user */
        $user = $request->user();
        $share->forceFill(['revoked_at' => now()])->save();
        $vault->log($share->document, 'revoke', $user);

        return back()->with('status', 'Share revoked.');
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        /** @var User $user */
        $user = $request->user();
        $isPractice = $request->attributes->get('pn_context') === 'tenant';

        if ($isPractice) {
            Gate::authorize('createInTenant', [DocumentFolder::class, (string) AccessContext::currentTenantId()]);
        } else {
            Gate::authorize('createPersonal', DocumentFolder::class);
        }

        $folder = new DocumentFolder($data);
        $folder->forceFill(['scope' => $isPractice ? 'tenant' : 'personal', 'tenant_id' => $isPractice ? AccessContext::currentTenantId() : null, 'owner_user_id' => $user->id])->save();

        return back()->with('status', 'Folder created.');
    }

    /** @return array<string, mixed> */
    private function details(UploadDocumentRequest $request): array
    {
        return $request->safe()->only(['title', 'category', 'confidentiality', 'folder_id']);
    }

    /** Marking a document privileged needs the right to see privileged material (D4.3). */
    private function checkPrivilegedAllowed(UploadDocumentRequest $request, User $user): void
    {
        if ($request->input('confidentiality') === 'privileged') {
            abort_unless(AccessContext::tenantCan($user, 'tenant_documents.view_privileged'), 403);
        }
    }
}
