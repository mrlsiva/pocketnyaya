<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The approved record conditions: assigned, lead, team, RC-MV, RC-MU and RC-CV
 * (Step 3, D4.0). Everything is limited to the request's current tenant.
 */
class MatterAccess
{
    /** The user's active membership in the current tenant: "me" in the design. */
    public static function me(User $user): ?string
    {
        $tenant = AccessContext::currentTenantId();
        if ($tenant === null || ! AccessContext::isActiveMember($user, $tenant)) {
            return null;
        }

        $id = DB::table('tenant_memberships')->where(['tenant_id' => $tenant, 'user_id' => $user->id])->value('id');

        return is_string($id) ? $id : null;
    }

    public static function assigned(User $user, Matter $matter): bool
    {
        return self::assignment($user, $matter)->exists();
    }

    public static function lead(User $user, Matter $matter): bool
    {
        return self::assignment($user, $matter)->where('role_on_matter', 'lead')->exists();
    }

    public static function team(User $user, Matter $matter): bool
    {
        $me = self::me($user);
        if ($me === null) {
            return false;
        }

        return self::assigned($user, $matter)
            || ($matter->practice_group_id !== null && in_array($matter->practice_group_id, self::myGroups($me), true))
            || DB::table('matter_assignments as a')
                ->join('tenant_memberships as m', 'm.id', '=', 'a.membership_id')
                ->where('a.matter_id', $matter->id)->whereNull('a.unassigned_at')
                ->where('m.supervisor_membership_id', $me)->exists();
    }

    /** RC-MV */
    public static function canView(User $user, Matter $matter): bool
    {
        if ($matter->trashed() || ! AccessContext::inTenant($user, $matter->tenant_id)) {
            return false;
        }

        if (AccessContext::tenantCan($user, 'matters.view_all')) {
            return true;
        }

        if ($matter->status === 'unallocated') {
            if (AccessContext::tenantCan($user, 'matters.allocate_all')) {
                return true;
            }
            $me = self::me($user);

            return AccessContext::tenantCan($user, 'matters.allocate_team') && $me !== null
                && $matter->practice_group_id !== null && in_array($matter->practice_group_id, self::myGroups($me), true);
        }

        return (AccessContext::tenantCan($user, 'matters.view_team') && self::team($user, $matter))
            || (AccessContext::tenantCan($user, 'matters.view_assigned') && self::assigned($user, $matter));
    }

    /** RC-MU */
    public static function canUpdate(User $user, Matter $matter): bool
    {
        return self::canView($user, $matter) && $matter->isWritable()
            && (AccessContext::tenantCan($user, 'matters.update_all')
                || (AccessContext::tenantCan($user, 'matters.update_assigned') && self::assigned($user, $matter)));
    }

    /** RC-MV ∧ (assigned ∨ matters.view_all): the shared rule for notes and hearing changes. */
    public static function canWorkOn(User $user, Matter $matter): bool
    {
        return self::canView($user, $matter)
            && (AccessContext::tenantCan($user, 'matters.view_all') || self::assigned($user, $matter));
    }

    /** RC-CV */
    public static function canViewClient(User $user, Client $client): bool
    {
        if ($client->trashed() || ! AccessContext::inTenant($user, $client->tenant_id)) {
            return false;
        }
        if (AccessContext::tenantCan($user, 'clients.view_all')) {
            return true;
        }
        if (! AccessContext::tenantCan($user, 'clients.view_assigned')) {
            return false;
        }

        return self::visibleMatters(Matter::query(), $user)->where('client_id', $client->id)->exists();
    }

    /**
     * Mirrors canView for lists.
     *
     * @param  Builder<Matter>  $query
     * @return Builder<Matter>
     */
    public static function visibleMatters(Builder $query, User $user): Builder
    {
        $tenant = AccessContext::currentTenantId();
        $me = self::me($user);
        if ($tenant === null || $me === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('matters.tenant_id', $tenant);
        if (AccessContext::tenantCan($user, 'matters.view_all')) {
            return $query;
        }

        $groups = self::myGroups($me);
        $allocateAll = AccessContext::tenantCan($user, 'matters.allocate_all');
        $allocateTeam = AccessContext::tenantCan($user, 'matters.allocate_team');
        $viewTeam = AccessContext::tenantCan($user, 'matters.view_team');
        $viewAssigned = AccessContext::tenantCan($user, 'matters.view_assigned');

        $assignedTo = fn (string $membership) => function (QueryBuilder $sub) use ($membership) {
            $sub->selectRaw('1')->from('matter_assignments as a')->whereColumn('a.matter_id', 'matters.id')
                ->where('a.membership_id', $membership)->whereNull('a.unassigned_at');
        };

        return $query->where(function (Builder $visible) use ($me, $groups, $allocateAll, $allocateTeam, $viewTeam, $viewAssigned, $assignedTo) {
            $visible->where(function (Builder $unallocated) use ($groups, $allocateAll, $allocateTeam) {
                $unallocated->where('matters.status', 'unallocated');
                if ($allocateAll) {
                    return;
                }
                $allocateTeam ? $unallocated->whereIn('matters.practice_group_id', $groups) : $unallocated->whereRaw('1 = 0');
            });

            $visible->orWhere(function (Builder $allocated) use ($me, $groups, $viewTeam, $viewAssigned, $assignedTo) {
                $allocated->where('matters.status', '<>', 'unallocated')->where(function (Builder $rule) use ($me, $groups, $viewTeam, $viewAssigned, $assignedTo) {
                    $rule->whereRaw('1 = 0');
                    if ($viewAssigned || $viewTeam) {
                        $rule->orWhereExists($assignedTo($me));
                    }
                    if ($viewTeam) {
                        $rule->orWhereIn('matters.practice_group_id', $groups);
                        $rule->orWhereExists(function (QueryBuilder $sub) use ($me) {
                            $sub->selectRaw('1')->from('matter_assignments as a')
                                ->join('tenant_memberships as m', 'm.id', '=', 'a.membership_id')
                                ->whereColumn('a.matter_id', 'matters.id')->whereNull('a.unassigned_at')
                                ->where('m.supervisor_membership_id', $me);
                        });
                    }
                });
            });
        });
    }

    /**
     * Mirrors canViewClient for lists.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function visibleClients(Builder $query, User $user): Builder
    {
        $tenant = AccessContext::currentTenantId();
        if ($tenant === null || ! AccessContext::inTenant($user, $tenant)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('clients.tenant_id', $tenant);
        if (AccessContext::tenantCan($user, 'clients.view_all')) {
            return $query;
        }
        if (! AccessContext::tenantCan($user, 'clients.view_assigned')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('clients.id', self::visibleMatters(Matter::query(), $user)->select('matters.client_id'));
    }

    private static function assignment(User $user, Matter $matter): QueryBuilder
    {
        return DB::table('matter_assignments')
            ->where('matter_id', $matter->id)
            ->where('membership_id', self::me($user) ?? '')
            ->whereNull('unassigned_at');
    }

    /** @return list<string> */
    private static function myGroups(string $me): array
    {
        return DB::table('practice_group_members')->where('membership_id', $me)
            ->pluck('practice_group_id')
            ->merge(DB::table('practice_groups')->where('lead_membership_id', $me)->whereNull('deleted_at')->pluck('id'))
            ->map(fn ($id) => (string) $id)->values()->all();
    }
}
