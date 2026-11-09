<?php

namespace App\Policies;

use App\Models\DocumentFolder;
use App\Models\User;
use App\Support\AccessContext;
use Illuminate\Auth\Access\Response;

/**
 * Implements D8.8 for folders. Folders hold no content themselves, but their
 * names can reveal it, so out-of-scope folders are 404.
 */
class DocumentFolderPolicy
{
    public function view(User $user, DocumentFolder $folder): Response
    {
        return $this->canView($user, $folder) ? Response::allow() : Response::denyAsNotFound();
    }

    public function createInTenant(User $user, string $tenantId): Response
    {
        return AccessContext::can($user, 'tenant_folders.manage', $tenantId) ? Response::allow() : Response::deny();
    }

    public function createPersonal(User $user): Response
    {
        return AccessContext::citizenCan($user, 'vault.folders.manage_own') ? Response::allow() : Response::deny();
    }

    public function update(User $user, DocumentFolder $folder): Response
    {
        if (! $this->canView($user, $folder)) {
            return Response::denyAsNotFound();
        }

        return $this->canManage($user, $folder) ? Response::allow() : Response::deny();
    }

    /** A folder can be deleted only when it's empty. */
    public function delete(User $user, DocumentFolder $folder): Response
    {
        $response = $this->update($user, $folder);
        if ($response->denied()) {
            return $response;
        }

        $hasContent = $folder->documents()->exists() || $folder->children()->exists();

        return $hasContent ? Response::denyWithStatus(422) : Response::allow();
    }

    private function canView(User $user, DocumentFolder $folder): bool
    {
        return $folder->scope === 'personal'
            ? $this->ownsPersonal($user, $folder)
            : AccessContext::can($user, 'tenant_documents.view', $folder->tenant_id);
    }

    private function canManage(User $user, DocumentFolder $folder): bool
    {
        return $folder->scope === 'personal'
            ? $this->ownsPersonal($user, $folder)
            : AccessContext::can($user, 'tenant_folders.manage', $folder->tenant_id);
    }

    private function ownsPersonal(User $user, DocumentFolder $folder): bool
    {
        return $folder->owner_user_id === $user->id
            && AccessContext::citizenCan($user, 'vault.folders.manage_own');
    }
}
