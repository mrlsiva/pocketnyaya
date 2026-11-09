<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\User;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DA-28(b): documents go only to recipients who already have a relationship with
 * the sharer. DA-14: a vault shares with a named user unless a practice is chosen.
 */
class ShareService
{
    public function __construct(private DocumentVault $vault) {}

    public function shareWithUser(Document $document, User $sharer, User $grantee, string $permission, ?string $expiresAt): DocumentShare
    {
        $this->guardSharer($document, $sharer, $permission);

        $allowed = $document->scope === 'personal'
            ? $this->vaultOwnerConsultedAdvocate($sharer, $grantee)
            : $this->tenantDocumentForLinkedClient($document, $grantee);

        if (! $allowed) {
            throw ValidationException::withMessages(['recipient' => 'You can share only with someone you already work with on this platform.']);
        }

        return $this->create($document, $sharer, ['grantee_type' => 'user', 'grantee_user_id' => $grantee->id], $permission, $expiresAt);
    }

    public function shareWithTenant(Document $document, User $sharer, string $tenantId, string $permission, ?string $expiresAt): DocumentShare
    {
        $this->guardSharer($document, $sharer, $permission);

        $related = $document->scope === 'personal' && (
            DB::table('consultation_requests')->where(['customer_user_id' => $sharer->id, 'tenant_id' => $tenantId])
                ->whereIn('status', ['accepted', 'scheduled', 'completed'])->whereNull('deleted_at')->exists()
            || DB::table('clients')->where(['tenant_id' => $tenantId, 'customer_user_id' => $sharer->id])->whereNull('deleted_at')->exists()
        );
        if (! $related) {
            throw ValidationException::withMessages(['recipient' => 'You can share only with a practice you already work with.']);
        }

        return $this->create($document, $sharer, ['grantee_type' => 'tenant', 'grantee_tenant_id' => $tenantId], $permission, $expiresAt);
    }

    /**
     * People this user can pick as a recipient for a vault document.
     *
     * @return list<object>
     */
    public function vaultRecipients(User $owner): array
    {
        return DB::table('consultation_requests as c')
            ->join('advocate_profiles as p', 'p.id', '=', 'c.advocate_profile_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('c.customer_user_id', $owner->id)->whereIn('c.status', ['accepted', 'scheduled', 'completed'])->whereNull('c.deleted_at')
            ->distinct()->get(['u.id', 'u.name'])->all();
    }

    private function guardSharer(Document $document, User $sharer, string $permission): void
    {
        if (! in_array($permission, ['view', 'download'], true)) {
            throw ValidationException::withMessages(['permission' => 'Choose view or download.']);
        }

        $can = match ($document->scope) {
            'personal' => $document->owner_user_id === $sharer->id && AccessContext::citizenCan($sharer, 'vault.documents.share_own'),
            'tenant' => AccessContext::can($sharer, 'tenant_documents.share_external', $document->tenant_id)
                && ($permission === 'view' || AccessContext::can($sharer, 'tenant_documents.download', $document->tenant_id)),
            default => false,
        };

        abort_unless($can, 403);
    }

    private function vaultOwnerConsultedAdvocate(User $owner, User $grantee): bool
    {
        return DB::table('consultation_requests as c')->join('advocate_profiles as p', 'p.id', '=', 'c.advocate_profile_id')
            ->where('c.customer_user_id', $owner->id)->where('p.user_id', $grantee->id)
            ->whereIn('c.status', ['accepted', 'scheduled', 'completed'])->whereNull('c.deleted_at')->exists();
    }

    private function tenantDocumentForLinkedClient(Document $document, User $grantee): bool
    {
        $clientId = $document->client_id ?? ($document->matter_id !== null
            ? DB::table('matters')->where('id', $document->matter_id)->value('client_id')
            : null);

        return $clientId !== null && DB::table('clients')
            ->where(['id' => $clientId, 'tenant_id' => $document->tenant_id, 'customer_user_id' => $grantee->id])->exists();
    }

    /** @param array<string, string> $grantee */
    private function create(Document $document, User $sharer, array $grantee, string $permission, ?string $expiresAt): DocumentShare
    {
        $share = new DocumentShare;
        $share->forceFill($grantee + ['document_id' => $document->id, 'shared_by' => $sharer->id, 'permission' => $permission, 'expires_at' => $expiresAt])->save();
        $this->vault->log($document, 'share', $sharer);

        return $share;
    }
}
