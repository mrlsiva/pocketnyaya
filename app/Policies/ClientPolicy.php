<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Auth\Access\Response;

/** Implements D8.5 for clients. */
class ClientPolicy
{
    public function create(User $user): Response
    {
        return AccessContext::tenantCan($user, 'clients.create') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Client $client): Response
    {
        return MatterAccess::canViewClient($user, $client) ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Client $client): Response
    {
        if (! MatterAccess::canViewClient($user, $client)) {
            return Response::denyAsNotFound();
        }

        return AccessContext::tenantCan($user, 'clients.update') ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Client $client): Response
    {
        if (! MatterAccess::canViewClient($user, $client)) {
            return Response::denyAsNotFound();
        }
        if (! AccessContext::tenantCan($user, 'clients.delete')) {
            return Response::deny();
        }

        return $client->matters()->withTrashed()->exists() ? Response::denyWithStatus(422, 'This client has matters.') : Response::allow();
    }

    /** RC-BILL: billing fields only, and never implies seeing matters or the rest of the client. */
    public function viewBillingProfile(User $user, Client $client): Response
    {
        return ! $client->trashed() && AccessContext::inTenant($user, $client->tenant_id) && AccessContext::tenantCan($user, 'billing.client_profiles.view')
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function updateBillingProfile(User $user, Client $client): Response
    {
        if ($this->viewBillingProfile($user, $client)->denied()) {
            return Response::denyAsNotFound();
        }

        return AccessContext::tenantCan($user, 'billing.client_profiles.update') ? Response::allow() : Response::deny();
    }
}
