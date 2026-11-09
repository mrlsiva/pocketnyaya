<?php

namespace App\Policies;

use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\MatterTask;
use App\Models\User;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Auth\Access\Response;

/** Implements D8.6. Out of scope is 404, visible but forbidden is 403, bad state is 422. */
class MatterPolicy
{
    public function create(User $user): Response
    {
        return AccessContext::tenantCan($user, 'matters.create') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Matter $matter): Response
    {
        return MatterAccess::canView($user, $matter) ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Matter $matter): Response
    {
        if (! MatterAccess::canView($user, $matter)) {
            return Response::denyAsNotFound();
        }
        if (! $matter->isWritable()) {
            return Response::denyWithStatus(422, 'This matter can no longer be changed.');
        }

        return MatterAccess::canUpdate($user, $matter) ? Response::allow() : Response::deny();
    }

    public function close(User $user, Matter $matter): Response
    {
        if (! MatterAccess::canView($user, $matter)) {
            return Response::denyAsNotFound();
        }
        $allowed = AccessContext::tenantCan($user, 'matters.close_all')
            || (AccessContext::tenantCan($user, 'matters.close_as_lead') && MatterAccess::lead($user, $matter));
        if (! $allowed) {
            return Response::deny();
        }

        return in_array($matter->status, ['open', 'on_hold', 'disposed'], true) ? Response::allow() : Response::denyWithStatus(422);
    }

    public function reopen(User $user, Matter $matter): Response
    {
        return $this->fromClosed($user, $matter);
    }

    public function archive(User $user, Matter $matter): Response
    {
        return $this->fromClosed($user, $matter);
    }

    public function delete(User $user, Matter $matter): Response
    {
        if (! MatterAccess::canView($user, $matter)) {
            return Response::denyAsNotFound();
        }
        if (! AccessContext::tenantCan($user, 'matters.delete')) {
            return Response::deny();
        }

        return in_array($matter->status, ['unallocated', 'open'], true) ? Response::allow() : Response::denyWithStatus(422);
    }

    public function allocate(User $user, Matter $matter): Response
    {
        if (! AccessContext::inTenant($user, $matter->tenant_id)) {
            return Response::denyAsNotFound();
        }
        $me = MatterAccess::me($user);
        $allowed = AccessContext::tenantCan($user, 'matters.allocate_all')
            || (AccessContext::tenantCan($user, 'matters.allocate_team') && $me !== null && MatterAccess::team($user, $matter));
        if (! $allowed) {
            return MatterAccess::canView($user, $matter) ? Response::deny() : Response::denyAsNotFound();
        }

        return in_array($matter->status, ['closed', 'archived'], true) ? Response::denyWithStatus(422) : Response::allow();
    }

    public function setBillingRestriction(User $user, Matter $matter): Response
    {
        if (! MatterAccess::canView($user, $matter)) {
            return Response::denyAsNotFound();
        }

        return AccessContext::tenantCan($user, 'matters.billing_restriction.manage') ? Response::allow() : Response::deny();
    }

    public function manageParties(User $user, Matter $matter): Response
    {
        return $this->needsUpdateAnd($user, $matter, 'matters.parties.manage');
    }

    public function manageTasks(User $user, Matter $matter): Response
    {
        return $this->needsUpdateAnd($user, $matter, 'matters.tasks.manage');
    }

    public function updateTaskStatus(User $user, MatterTask $task): Response
    {
        $me = MatterAccess::me($user);
        if ($me === null || $task->tenant_id !== AccessContext::currentTenantId() || $task->assigned_to_membership_id !== $me) {
            return Response::denyAsNotFound();
        }
        if (! AccessContext::tenantCan($user, 'matters.tasks.update_own_assigned')) {
            return Response::deny();
        }

        return $task->status === 'cancelled' ? Response::denyWithStatus(422) : Response::allow();
    }

    /** @param 'team'|'private'|'partners' $visibility */
    public function createNote(User $user, Matter $matter, string $visibility): Response
    {
        if (! MatterAccess::canWorkOn($user, $matter)) {
            return MatterAccess::canView($user, $matter) ? Response::deny() : Response::denyAsNotFound();
        }
        if ($matter->status === 'archived') {
            return Response::denyWithStatus(422);
        }
        if (! AccessContext::tenantCan($user, 'matters.notes.create')) {
            return Response::deny();
        }
        if ($visibility === 'partners' && ! $this->seesPartnerNotes($user, $matter)) {
            return Response::deny();
        }

        return Response::allow();
    }

    public function viewNote(User $user, MatterNote $note): Response
    {
        return $this->canViewNote($user, $note) ? Response::allow() : Response::denyAsNotFound();
    }

    public function changeNote(User $user, MatterNote $note): Response
    {
        if (! $this->canViewNote($user, $note) || $note->author_user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        return AccessContext::tenantCan($user, 'matters.notes.create') ? Response::allow() : Response::deny();
    }

    public function canViewNote(User $user, MatterNote $note): bool
    {
        /** @var Matter|null $matter */
        $matter = $note->matter;
        if ($matter === null || ! AccessContext::inTenant($user, $note->tenant_id)) {
            return false;
        }

        return match ($note->visibility) {
            // DA-15: visible only to the author, whatever role anyone else holds.
            'private' => $note->author_user_id === $user->id,
            'partners' => MatterAccess::canView($user, $matter) && $this->seesPartnerNotes($user, $matter),
            default => AccessContext::tenantCan($user, 'matters.notes.view_team') && MatterAccess::canWorkOn($user, $matter),
        };
    }

    private function seesPartnerNotes(User $user, Matter $matter): bool
    {
        return AccessContext::tenantCan($user, 'matters.notes.view_partner_level_all')
            || (AccessContext::tenantCan($user, 'matters.notes.view_partner_level_as_lead') && MatterAccess::lead($user, $matter));
    }

    private function fromClosed(User $user, Matter $matter): Response
    {
        if (! MatterAccess::canView($user, $matter)) {
            return Response::denyAsNotFound();
        }
        if (! AccessContext::tenantCan($user, 'matters.close_all')) {
            return Response::deny();
        }

        return $matter->status === 'closed' ? Response::allow() : Response::denyWithStatus(422);
    }

    private function needsUpdateAnd(User $user, Matter $matter, string $permission): Response
    {
        if (! MatterAccess::canView($user, $matter)) {
            return Response::denyAsNotFound();
        }
        if (! $matter->isWritable()) {
            return Response::denyWithStatus(422);
        }

        return MatterAccess::canUpdate($user, $matter) && AccessContext::tenantCan($user, $permission)
            ? Response::allow()
            : Response::deny();
    }
}
