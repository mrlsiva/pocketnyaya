<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\User;
use App\Support\AccessContext;
use Illuminate\Auth\Access\Response;

/**
 * Implements DocumentShare.revokeTenant and .revokePersonal from D8.8.
 * Creating a share is denied by DocumentPolicy::share until DA-28 data exists.
 */
class DocumentSharePolicy
{
    public function revoke(User $user, DocumentShare $share): Response
    {
        /** @var Document $document */
        $document = $share->document()->withTrashed()->firstOrFail();

        if ($document->scope === 'personal') {
            return $document->owner_user_id === $user->id && AccessContext::citizenCan($user, 'vault.documents.share_own')
                ? Response::allow()
                : Response::denyAsNotFound();
        }

        if (! AccessContext::inTenant($user, $document->tenant_id)) {
            return Response::denyAsNotFound();
        }

        return $share->shared_by === $user->id || AccessContext::can($user, 'tenant_documents.revoke_share', $document->tenant_id)
            ? Response::allow()
            : Response::deny();
    }
}
