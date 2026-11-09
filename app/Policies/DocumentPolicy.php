<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\Matter;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use App\Support\OdrAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;

/**
 * Implements D4.3 and D8.8. Status codes follow Step 3: out of scope is 404,
 * visible but forbidden is 403, invalid state is 422, shredded is 410.
 *
 * ODR files follow the dispute (D5.5). New shares go through ShareService (DA-28).
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): Response
    {
        return $this->canView($user, $document) ? Response::allow() : Response::denyAsNotFound();
    }

    public function download(User $user, Document $document): Response
    {
        if (! $this->canView($user, $document)) {
            return Response::denyAsNotFound();
        }
        if (! $this->canDownload($user, $document)) {
            return Response::deny();
        }

        $version = $document->latestVersion();
        if ($version === null) {
            return Response::denyAsNotFound();
        }

        return $version->isShredded() ? Response::denyWithStatus(410) : Response::allow();
    }

    /** RC-DUP(general) */
    public function createGeneral(User $user, string $tenantId): Response
    {
        return AccessContext::can($user, 'tenant_documents.manage_general', $tenantId)
            ? Response::allow()
            : Response::deny();
    }

    /** RC-DUP(matter): 404 if the matter isn't visible, 403 without rights, 422 for a closed matter. */
    public function createForMatter(User $user, Matter $matter): Response
    {
        if (! MatterAccess::canView($user, $matter)) {
            return Response::denyAsNotFound();
        }
        if ($matter->status === 'unallocated') {
            return AccessContext::tenantCan($user, 'tenant_documents.upload') ? Response::allow() : Response::deny();
        }
        if (! in_array($matter->status, ['open', 'on_hold', 'disposed'], true)) {
            return Response::denyWithStatus(422, 'This matter no longer accepts documents.');
        }

        return AccessContext::tenantCan($user, 'tenant_documents.upload') && MatterAccess::canWorkOn($user, $matter)
            ? Response::allow()
            : Response::deny();
    }

    /** RC-DUP(client) */
    public function createForClient(User $user, Client $client): Response
    {
        if (! MatterAccess::canViewClient($user, $client)) {
            return Response::denyAsNotFound();
        }

        return AccessContext::tenantCan($user, 'tenant_documents.upload') ? Response::allow() : Response::deny();
    }

    public function createPersonal(User $user): Response
    {
        return AccessContext::citizenCan($user, 'vault.documents.manage_own')
            ? Response::allow()
            : Response::deny();
    }

    public function createVerification(User $user, VerificationRequest $request): Response
    {
        if ($user->status !== 'active' || $request->applicantUserId() !== $user->id) {
            return Response::denyAsNotFound();
        }

        return $this->checkVerificationUpload($user, $request);
    }

    /** Adding a version is how a document's file is replaced. */
    public function uploadVersion(User $user, Document $document): Response
    {
        if ($document->scope === 'platform') {
            $request = $document->verificationRequest;
            if ($request === null || ! $this->ownsVerificationDocument($user, $document)) {
                return Response::denyAsNotFound();
            }

            return $this->checkVerificationUpload($user, $request);
        }

        if (! $this->canView($user, $document)) {
            return Response::denyAsNotFound();
        }

        if ($document->scope === 'personal') {
            return $this->ownsPersonal($user, $document) ? Response::allow() : Response::deny();
        }
        if (! AccessContext::inTenant($user, $document->tenant_id) || ! $this->canViewTenantDocument($user, $document)) {
            return Response::deny();
        }

        return $this->parentAccepts($user, $document);
    }

    public function update(User $user, Document $document): Response
    {
        if (! $this->canView($user, $document)) {
            return Response::denyAsNotFound();
        }
        if ($document->scope === 'personal') {
            return $this->ownsPersonal($user, $document) ? Response::allow() : Response::deny();
        }
        if ($document->scope !== 'tenant' || ! $this->canViewTenantDocument($user, $document)) {
            return Response::deny();
        }

        $permission = $document->isGeneralTenantDocument() ? 'tenant_documents.manage_general' : 'tenant_documents.update';

        return AccessContext::can($user, $permission, $document->tenant_id) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Document $document): Response
    {
        if (! $this->canView($user, $document)) {
            return Response::denyAsNotFound();
        }
        if ($document->scope === 'personal') {
            return $this->ownsPersonal($user, $document) ? Response::allow() : Response::deny();
        }
        if ($document->scope !== 'tenant' || ! $this->canViewTenantDocument($user, $document)
            || ! AccessContext::can($user, 'tenant_documents.delete', $document->tenant_id)) {
            return Response::deny();
        }
        if ($document->isGeneralTenantDocument() && ! AccessContext::can($user, 'tenant_documents.manage_general', $document->tenant_id)) {
            return Response::deny();
        }

        $matter = $document->matter_id !== null ? Matter::query()->find($document->matter_id) : null;

        return $matter !== null && in_array($matter->status, ['closed', 'archived'], true) ? Response::denyWithStatus(422) : Response::allow();
    }

    public function forceDelete(User $user, Document $document): Response
    {
        return $this->canView($user, $document) ? Response::deny() : Response::denyAsNotFound();
    }

    /** The relationship rule itself (DA-28) lives in ShareService, which is the only way to share. */
    public function share(User $user, Document $document): Response
    {
        if (! $this->canView($user, $document)) {
            return Response::denyAsNotFound();
        }

        return match ($document->scope) {
            'personal' => $document->owner_user_id === $user->id && AccessContext::citizenCan($user, 'vault.documents.share_own') ? Response::allow() : Response::deny(),
            'tenant' => AccessContext::can($user, 'tenant_documents.share_external', $document->tenant_id) ? Response::allow() : Response::deny(),
            default => Response::deny(),
        };
    }

    private function canView(User $user, Document $document): bool
    {
        if ($document->trashed()) {
            return false;
        }
        if ($this->hasActiveShare($user, $document) !== null) {
            return true;
        }

        return match ($document->scope) {
            'personal' => $this->ownsPersonal($user, $document),
            'tenant' => $this->canViewTenantDocument($user, $document),
            'platform' => $document->odr_dispute_id !== null ? OdrAccess::canSeeDocument($user, $document) : $this->canViewVerificationDocument($user, $document),
            default => false,
        };
    }

    private function canDownload(User $user, Document $document): bool
    {
        if ($document->scope === 'personal' && $this->ownsPersonal($user, $document)) {
            return true;
        }
        if ($document->scope === 'tenant' && $this->canViewTenantDocument($user, $document)
            && AccessContext::can($user, 'tenant_documents.download', $document->tenant_id)) {
            return true;
        }
        if ($document->odr_dispute_id !== null) {
            return OdrAccess::canSeeDocument($user, $document);
        }
        if ($document->scope === 'platform') {
            // Project owner's decision: applicants may download their own documents.
            return $this->ownsVerificationDocument($user, $document) || $this->isStaffViewableVerification($user, $document);
        }

        $share = $this->hasActiveShare($user, $document);
        if ($share === null || $share->permission !== 'download') {
            return false;
        }

        return $share->grantee_type === 'user'
            || AccessContext::can($user, 'shared_documents.download', $share->grantee_tenant_id);
    }

    /** RC-DUP for a document's current parent. */
    private function parentAccepts(User $user, Document $document): Response
    {
        if ($document->isGeneralTenantDocument()) {
            return AccessContext::can($user, 'tenant_documents.manage_general', $document->tenant_id) ? Response::allow() : Response::deny();
        }
        if ($document->matter_id !== null) {
            $matter = Matter::query()->find($document->matter_id);

            return $matter === null ? Response::denyAsNotFound() : $this->createForMatter($user, $matter);
        }
        $client = Client::query()->find($document->client_id);

        return $client === null ? Response::denyAsNotFound() : $this->createForClient($user, $client);
    }

    private function ownsPersonal(User $user, Document $document): bool
    {
        return $document->owner_user_id === $user->id
            && AccessContext::citizenCan($user, 'vault.documents.manage_own');
    }

    /** The class rule from D4.3, plus RC-PRIV. */
    private function canViewTenantDocument(User $user, Document $document): bool
    {
        if (! AccessContext::can($user, 'tenant_documents.view', $document->tenant_id)) {
            return false;
        }

        $matter = $document->matter_id !== null ? Matter::query()->find($document->matter_id) : null;
        if ($document->matter_id !== null) {
            if ($matter === null || ! MatterAccess::canView($user, $matter)) {
                return false;
            }
        } elseif ($document->client_id !== null) {
            $client = Client::query()->find($document->client_id);
            if ($client === null || ! MatterAccess::canViewClient($user, $client)) {
                return false;
            }
        }

        if ($document->confidentiality !== 'privileged') {
            return true;
        }
        if (! AccessContext::can($user, 'tenant_documents.view_privileged', $document->tenant_id)) {
            return false;
        }
        if (AccessContext::can($user, 'matters.view_all', $document->tenant_id)) {
            return true;
        }

        return $matter !== null && DB::table('matter_assignments')->where('matter_id', $matter->id)
            ->where('membership_id', MatterAccess::me($user))->whereNull('unassigned_at')
            ->whereIn('role_on_matter', ['lead', 'associate'])->exists();
    }

    private function canViewVerificationDocument(User $user, Document $document): bool
    {
        return $this->ownsVerificationDocument($user, $document)
            || $this->isStaffViewableVerification($user, $document);
    }

    private function ownsVerificationDocument(User $user, Document $document): bool
    {
        return $user->status === 'active' && $document->verificationRequest?->applicantUserId() === $user->id;
    }

    private function isStaffViewableVerification(User $user, Document $document): bool
    {
        $request = $document->verificationRequest;

        return $request !== null
            && in_array($request->status, VerificationRequest::STAFF_VISIBLE_STATUSES, true)
            && AccessContext::staffCan($user, 'verification.documents.view');
    }

    private function checkVerificationUpload(User $user, VerificationRequest $request): Response
    {
        $permission = $request->mediator_profile_id !== null ? 'verification.mediator.apply' : 'verification.advocate.apply';
        if (! AccessContext::citizenCan($user, $permission)) {
            return Response::deny();
        }

        return $request->acceptsDocuments() ? Response::allow() : Response::denyWithStatus(422);
    }

    /** RC-SHARE: a user grant is self-service, a tenant grant needs that tenant's context. */
    private function hasActiveShare(User $user, Document $document): ?DocumentShare
    {
        if ($user->status !== 'active') {
            return null;
        }

        // 'download' sorts before 'view', so the broadest active grant wins.
        $userShare = $document->shares()->active()->where('grantee_user_id', $user->id)->orderBy('permission')->first();
        if ($userShare !== null) {
            return $userShare;
        }

        $tenant = AccessContext::currentTenantId();
        if ($tenant === null || ! AccessContext::can($user, 'shared_documents.view', $tenant)) {
            return null;
        }

        return $document->shares()->active()->where('grantee_tenant_id', $tenant)->orderBy('permission')->first();
    }
}
