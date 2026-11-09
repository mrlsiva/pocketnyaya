<?php

namespace App\Policies;

use App\Models\Hearing;
use App\Models\Matter;
use App\Models\User;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Auth\Access\Response;

/** Implements D8.7 for hearings. */
class HearingPolicy
{
    public function create(User $user, Matter $matter): Response
    {
        if (! MatterAccess::canView($user, $matter) || ! AccessContext::tenantCan($user, 'hearings.view')) {
            return Response::denyAsNotFound();
        }
        if (! AccessContext::tenantCan($user, 'hearings.manage') || ! MatterAccess::canWorkOn($user, $matter)) {
            return Response::deny();
        }

        return in_array($matter->status, ['open', 'on_hold', 'disposed'], true) ? Response::allow() : Response::denyWithStatus(422);
    }

    public function view(User $user, Hearing $hearing): Response
    {
        $matter = $hearing->matter;

        return $matter !== null && MatterAccess::canView($user, $matter) && AccessContext::tenantCan($user, 'hearings.view')
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Hearing $hearing): Response
    {
        $matter = $hearing->matter;

        return $matter === null ? Response::denyAsNotFound() : $this->create($user, $matter);
    }

    public function recordOutcome(User $user, Hearing $hearing): Response
    {
        $view = $this->view($user, $hearing);
        if ($view->denied()) {
            return $view;
        }
        /** @var Matter $matter */
        $matter = $hearing->matter;
        if (! AccessContext::tenantCan($user, 'hearings.manage') || ! MatterAccess::canWorkOn($user, $matter)) {
            return Response::deny();
        }

        return $matter->status === 'archived' ? Response::denyWithStatus(422) : Response::allow();
    }

    public function delete(User $user, Hearing $hearing): Response
    {
        $view = $this->view($user, $hearing);
        if ($view->denied()) {
            return $view;
        }
        if (! AccessContext::tenantCan($user, 'hearings.delete')) {
            return Response::deny();
        }

        return $hearing->status === 'scheduled' && $hearing->outcome_summary === null ? Response::allow() : Response::denyWithStatus(422);
    }
}
