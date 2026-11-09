<?php

namespace App\Services;

use App\Models\User;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;

class WorkspaceService
{
    /**
     * The workspaces this user can enter. No data crosses between them (DA-13).
     *
     * @return list<array{kind: string, tenant_id: string|null, name: string, roles: list<string>}>
     */
    public function forUser(User $user): array
    {
        $workspaces = [[
            'kind' => 'citizen',
            'tenant_id' => null,
            'name' => 'My personal account',
            'roles' => $this->rolesIn($user, AccessContext::platformTenantId(), false),
        ]];

        $practices = DB::table('tenant_memberships as m')
            ->join('tenants as t', 't.id', '=', 'm.tenant_id')
            ->where('m.user_id', $user->id)->where('m.status', 'active')->whereNull('m.deleted_at')
            ->where('t.status', 'active')->whereNull('t.deleted_at')->whereIn('t.type', ['solo', 'firm'])
            ->orderBy('t.name')->get(['t.id', 't.name']);

        foreach ($practices as $practice) {
            $workspaces[] = [
                'kind' => 'tenant',
                'tenant_id' => (string) $practice->id,
                'name' => (string) $practice->name,
                'roles' => $this->rolesIn($user, (string) $practice->id, true),
            ];
        }

        if (AccessContext::isStaff($user)) {
            $workspaces[] = [
                'kind' => 'staff',
                'tenant_id' => null,
                'name' => 'Platform administration',
                'roles' => $this->rolesIn($user, AccessContext::platformTenantId(), true),
            ];
        }

        return $workspaces;
    }

    /** @return list<string> */
    private function rolesIn(User $user, ?string $tenantId, bool $requiresMembership): array
    {
        return DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')
            ->where('r.team_id', $tenantId)->where('r.model_id', $user->id)
            ->where('roles.requires_membership', $requiresMembership)
            ->orderBy('roles.name')->pluck('roles.name')->map(fn ($name) => (string) $name)->all();
    }
}
