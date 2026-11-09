<?php

namespace App\Services;

use App\Models\User;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * The single entry point for role changes (C26). The database triggers remain
 * the backstop. Spatie writes the current team id into the pivot, so the team
 * is set for the call and always restored.
 */
class RoleAssignmentService
{
    public function assign(User $user, string $roleName, string $tenantId): void
    {
        $this->withinTeam($tenantId, $user, fn () => $user->assignRole($roleName));
    }

    public function revoke(User $user, string $roleName, string $tenantId): void
    {
        $this->withinTeam($tenantId, $user, fn () => $user->removeRole($roleName));
    }

    /** DA-4: every registered user holds Customer in the platform tenant. */
    public function assignCustomer(User $user): void
    {
        $platform = AccessContext::platformTenantId();
        if ($platform === null) {
            throw new RuntimeException('The platform tenant has not been seeded.');
        }

        $this->assign($user, 'Customer', $platform);
    }

    private function withinTeam(string $tenantId, User $user, callable $change): void
    {
        $previous = getPermissionsTeamId();

        DB::transaction(function () use ($tenantId, $user, $change) {
            setPermissionsTeamId($tenantId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $change();
        });

        setPermissionsTeamId($previous);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
