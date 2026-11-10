<?php

namespace App\Services;

use App\Models\Matter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The firm case allocation matrix (PRD 2.4): for one matter, every active member with their
 * availability today, their practice areas, whether those match the matter, and their current
 * load against their limit. It informs the partner's choice and never assigns by itself.
 */
class AllocationService
{
    /** @return Collection<int, AllocationCandidate> */
    public function candidates(Matter $matter): Collection
    {
        $tenant = $matter->tenant_id;
        $today = now()->toDateString();
        $members = DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $tenant)->where('m.status', 'active')->whereNull('m.deleted_at')
            ->orderBy('u.name')->get(['m.id', 'm.user_id', 'm.max_active_matters', 'u.name']);
        $ids = $members->pluck('id')->all();

        $areas = DB::table('membership_practice_areas as a')->join('practice_areas as p', 'p.id', '=', 'a.practice_area_id')
            ->whereIn('a.membership_id', $ids)->get(['a.membership_id', 'a.practice_area_id', 'p.name'])->groupBy('membership_id');
        $loads = DB::table('matter_assignments as a')->join('matters as x', 'x.id', '=', 'a.matter_id')
            ->whereIn('a.membership_id', $ids)->whereNull('a.unassigned_at')->whereNull('x.deleted_at')->whereNotIn('x.status', ['closed', 'archived'])
            ->groupBy('a.membership_id')->selectRaw('a.membership_id, COUNT(DISTINCT a.matter_id) c')->pluck('c', 'membership_id');
        $away = DB::table('membership_availability')->whereIn('membership_id', $ids)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)
            ->orderBy('ends_on')->get(['membership_id', 'availability', 'ends_on'])->keyBy('membership_id');
        $roles = DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')->where('r.team_id', $tenant)
            ->whereIn('r.model_id', $members->pluck('user_id'))->get(['r.model_id', 'roles.name'])->groupBy('model_id');

        return $members->map(function ($m) use ($matter, $areas, $loads, $away, $roles) {
            $mine = $areas->get($m->id, collect());
            $load = (int) ($loads[$m->id] ?? 0);
            $limit = $m->max_active_matters === null ? null : (int) $m->max_active_matters;
            $slot = $away->get($m->id);

            return new AllocationCandidate(
                id: (string) $m->id,
                name: (string) $m->name,
                roles: $roles->get($m->user_id, collect())->pluck('name')->map(fn ($n) => (string) $n)->values()->all(),
                areas: $mine->pluck('name')->map(fn ($n) => (string) $n)->values()->all(),
                matches_area: $matter->practice_area_id !== null && $mine->contains('practice_area_id', $matter->practice_area_id),
                availability: $slot !== null ? (string) $slot->availability : 'available',
                unavailable_until: $slot !== null ? (string) $slot->ends_on : null,
                active_matters: $load,
                max_active_matters: $limit,
                at_capacity: $limit !== null && $load >= $limit,
            );
        })->sortBy(fn (AllocationCandidate $c) => [
            $c->availability === 'available' ? 0 : ($c->availability === 'partial' ? 1 : 2),
            $c->at_capacity ? 1 : 0,
            $c->matches_area ? 0 : 1,
            $c->active_matters,
            $c->name,
        ])->values();
    }
}
