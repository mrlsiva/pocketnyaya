<?php

use App\Models\User;
use App\Support\AccessContext;
use Illuminate\Support\Facades\Broadcast;

/*
 * Private real-time channels. Ids are UUID strings, so they are compared exactly (never cast
 * to integers, which would make every id equal). Signals carry ids only, never content.
 */

// A person's own updates: tasks and matters assigned to them, new messages for them.
Broadcast::channel('users.{id}', fn (User $user, string $id) => $user->status === 'active' && $user->id === $id);

// A firm's diary: active members who may see hearings in that firm.
Broadcast::channel('tenants.{tenantId}.diary', fn (User $user, string $tenantId) => AccessContext::isActiveMember($user, $tenantId)
    && AccessContext::hasPermissionIn($user, 'hearings.view', $tenantId));
