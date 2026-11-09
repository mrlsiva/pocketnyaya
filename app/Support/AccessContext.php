<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reads the approved access contexts (D5.1). The current tenant is Spatie's team
 * id, which the context middleware sets for each request.
 *
 * Permissions are always checked against a named tenant, so a role held in one
 * firm can never answer a question about another, and a permission is honoured
 * only in its own context kind. There is no bypass for any role (DA-1).
 */
class AccessContext
{
    public static function currentTenantId(): ?string
    {
        $id = getPermissionsTeamId();

        return is_string($id) ? $id : null;
    }

    public static function platformTenantId(): ?string
    {
        $id = DB::table('tenants')->where('type', 'platform')->whereNull('deleted_at')->value('id');

        return is_string($id) ? $id : null;
    }

    /** staff, citizen or tenant, from the approved register. Null if unknown. */
    public static function kindOf(string $permission): ?string
    {
        foreach ((array) config('pocketnyaya.permissions') as $kind => $permissions) {
            if (array_key_exists($permission, (array) $permissions)) {
                return (string) $kind;
            }
        }

        return null;
    }

    public static function hasPermissionIn(User $user, string $permission, ?string $tenantId): bool
    {
        if ($tenantId === null || $user->status !== 'active') {
            return false;
        }

        $previous = getPermissionsTeamId();
        setPermissionsTeamId($tenantId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $user->checkPermissionTo($permission);
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /** Active membership in an active tenant. */
    public static function isActiveMember(User $user, ?string $tenantId): bool
    {
        if ($tenantId === null || $user->status !== 'active') {
            return false;
        }

        return DB::table('tenant_memberships as m')
            ->join('tenants as t', 't.id', '=', 'm.tenant_id')
            ->where('m.tenant_id', $tenantId)
            ->where('m.user_id', $user->id)
            ->where('m.status', 'active')
            ->whereNull('m.deleted_at')
            ->where('t.status', 'active')
            ->whereNull('t.deleted_at')
            ->exists();
    }

    /** RC-T: the current context is this tenant, and the user is an active member. */
    public static function inTenant(User $user, ?string $tenantId): bool
    {
        return $tenantId !== null
            && self::currentTenantId() === $tenantId
            && self::isActiveMember($user, $tenantId);
    }

    /** RC-T plus a permission held in that tenant. */
    public static function can(User $user, string $permission, ?string $tenantId): bool
    {
        return self::kindOf($permission) !== 'citizen'
            && self::inTenant($user, $tenantId)
            && self::hasPermissionIn($user, $permission, $tenantId);
    }

    /** Staff context: the current context is the platform tenant, with a staff permission. */
    public static function staffCan(User $user, string $permission): bool
    {
        return self::kindOf($permission) === 'staff'
            && self::can($user, $permission, self::platformTenantId());
    }

    /** Citizen permissions are held in the platform tenant, whatever the current firm context is. */
    public static function citizenCan(User $user, string $permission): bool
    {
        return self::kindOf($permission) === 'citizen'
            && self::hasPermissionIn($user, $permission, self::platformTenantId());
    }

    /** A tenant-kind permission in the request's current tenant. */
    public static function tenantCan(User $user, string $permission): bool
    {
        return self::kindOf($permission) === 'tenant'
            && self::can($user, $permission, self::currentTenantId());
    }

    /** Whether the user holds any staff role through an active platform membership. */
    public static function isStaff(User $user): bool
    {
        $platform = self::platformTenantId();

        return self::isActiveMember($user, $platform)
            && DB::table('model_has_roles as r')
                ->join('roles', 'roles.id', '=', 'r.role_id')
                ->where('r.team_id', $platform)
                ->where('r.model_id', $user->id)
                ->where('roles.requires_membership', true)
                ->exists();
    }
}
