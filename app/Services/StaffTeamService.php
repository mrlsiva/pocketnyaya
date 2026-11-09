<?php

namespace App\Services;

use App\Models\User;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * D9 rules for platform staff roles.
 *
 * - Compliance Officer: granted or revoked with platform.staff_roles.assign, and needs a platform membership.
 * - Super Admin: one Super Admin proposes, a different one approves. No one proposes or approves themselves.
 * - At least two active Super Admins at all times, so a revocation can't take the count below two.
 *
 * Proposals are not a new table: they are the approved audit events superadmin.proposed,
 * superadmin.approved and superadmin.proposal_withdrawn, which are append-only.
 * Changes run under a lock on the platform tenant row, so two staff can't race.
 */
class StaffTeamService
{
    public const MIN_SUPER_ADMINS = 2;

    public function __construct(private RoleAssignmentService $roles, private AuditLogWriter $audit) {}

    public function grantComplianceOfficer(User $actor, User $target): void
    {
        $this->guardTarget($actor, $target);
        $this->locked(function (string $platform) use ($target) {
            if ($this->holds($target, 'Compliance Officer')) {
                throw ValidationException::withMessages(['email' => 'This person is already a Compliance Officer.']);
            }
            $this->ensureMembership($target, $platform);
            $this->roles->assign($target, 'Compliance Officer', $platform);
            $this->audit->record('role.assigned', 'user', $target->id, null, null, ['role' => 'Compliance Officer']);
        });
    }

    public function revokeComplianceOfficer(User $actor, User $target): void
    {
        $this->guardNotSelf($actor, $target);
        $this->locked(function (string $platform) use ($target) {
            if (! $this->holds($target, 'Compliance Officer')) {
                throw ValidationException::withMessages(['email' => 'This person is not a Compliance Officer.']);
            }
            $this->roles->revoke($target, 'Compliance Officer', $platform);
            $this->audit->record('role.revoked', 'user', $target->id, null, ['role' => 'Compliance Officer'], null);
        });
    }

    public function proposeSuperAdmin(User $actor, User $target): void
    {
        $this->guardTarget($actor, $target);
        $this->locked(function () use ($target) {
            if ($this->holds($target, 'Super Admin')) {
                throw ValidationException::withMessages(['email' => 'This person is already a Super Admin.']);
            }
            if ($this->openProposal($target) !== null) {
                throw ValidationException::withMessages(['email' => 'There is already an open proposal for this person.']);
            }
            $this->audit->record('superadmin.proposed', 'user', $target->id);
        });
    }

    public function approveSuperAdmin(User $actor, User $target): void
    {
        $this->guardNotSelf($actor, $target);
        $this->locked(function (string $platform) use ($actor, $target) {
            $proposal = $this->openProposal($target);
            if ($proposal === null) {
                throw ValidationException::withMessages(['proposal' => 'There is no open proposal for this person.']);
            }
            if ($proposal->actor_user_id === $actor->id) {
                throw ValidationException::withMessages(['proposal' => 'A different Super Admin must approve your proposal.']);
            }
            if ($target->status !== 'active' || $target->email_verified_at === null) {
                throw ValidationException::withMessages(['proposal' => 'This account is no longer active and verified.']);
            }
            $this->ensureMembership($target, $platform);
            $this->roles->assign($target, 'Super Admin', $platform);
            $this->audit->record('superadmin.approved', 'user', $target->id, null, null, ['proposed_by' => $proposal->actor_user_id]);
        });
    }

    public function withdrawProposal(User $actor, User $target): void
    {
        $this->locked(function () use ($target) {
            if ($this->openProposal($target) === null) {
                throw ValidationException::withMessages(['proposal' => 'There is no open proposal for this person.']);
            }
            $this->audit->record('superadmin.proposal_withdrawn', 'user', $target->id);
        });
    }

    public function revokeSuperAdmin(User $actor, User $target): void
    {
        $this->guardNotSelf($actor, $target);
        $this->locked(function (string $platform) use ($target) {
            if (! $this->holds($target, 'Super Admin')) {
                throw ValidationException::withMessages(['email' => 'This person is not a Super Admin.']);
            }
            if ($this->activeSuperAdminCount() <= self::MIN_SUPER_ADMINS) {
                throw ValidationException::withMessages(['email' => 'At least '.self::MIN_SUPER_ADMINS.' active Super Admins are required, so this one can\'t be removed yet.']);
            }
            $this->roles->revoke($target, 'Super Admin', $platform);
            $this->audit->record('role.revoked', 'user', $target->id, null, ['role' => 'Super Admin'], null);
        });
    }

    /** The latest proposal event for this person, if it is still open. */
    public function openProposal(User $target): ?object
    {
        $latest = DB::table('audit_logs')->where(['auditable_type' => 'user', 'auditable_id' => $target->id])
            ->whereIn('event', ['superadmin.proposed', 'superadmin.approved', 'superadmin.proposal_withdrawn'])
            ->orderByDesc('id')->first(['event', 'actor_user_id', 'created_at']);

        return $latest !== null && $latest->event === 'superadmin.proposed' ? $latest : null;
    }

    /** @return list<object> Open proposals, newest first, with who proposed them. */
    public function openProposals(): array
    {
        $targets = DB::table('audit_logs')->where('event', 'superadmin.proposed')->where('auditable_type', 'user')->distinct()->pluck('auditable_id');
        $open = [];
        foreach (DB::table('users')->whereIn('id', $targets)->get(['id', 'name', 'email']) as $person) {
            $proposal = $this->openProposal(User::query()->findOrFail($person->id));
            if ($proposal !== null) {
                $open[] = (object) ['user' => $person, 'proposed_by' => $proposal->actor_user_id, 'proposed_at' => $proposal->created_at,
                    'proposer_name' => DB::table('users')->where('id', $proposal->actor_user_id)->value('name')];
            }
        }

        return $open;
    }

    public function activeSuperAdminCount(): int
    {
        $platform = (string) AccessContext::platformTenantId();

        return DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')
            ->join('users as u', 'u.id', '=', 'r.model_id')
            ->join('tenant_memberships as m', fn ($j) => $j->on('m.user_id', '=', 'u.id')->where('m.tenant_id', '=', $platform))
            ->where(['r.team_id' => $platform, 'roles.name' => 'Super Admin', 'u.status' => 'active', 'm.status' => 'active'])
            ->whereNull('m.deleted_at')->distinct()->count('u.id');
    }

    public function holds(User $user, string $role): bool
    {
        return DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')
            ->where(['r.team_id' => AccessContext::platformTenantId(), 'r.model_id' => $user->id, 'roles.name' => $role])->exists();
    }

    /** No self-elevation, and staff roles go only to active, verified accounts. */
    private function guardTarget(User $actor, User $target): void
    {
        $this->guardNotSelf($actor, $target);
        if ($target->status !== 'active' || $target->email_verified_at === null) {
            throw ValidationException::withMessages(['email' => 'Staff roles can only go to an active account with a verified email.']);
        }
    }

    private function guardNotSelf(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages(['email' => 'You cannot change your own staff roles.']);
        }
    }

    /** C24: staff roles need an active platform membership. A left or suspended one is reactivated. */
    private function ensureMembership(User $user, string $platform): void
    {
        // One row per tenant and user, including removed ones, so an old row is restored rather than duplicated.
        $membership = DB::table('tenant_memberships')->where(['tenant_id' => $platform, 'user_id' => $user->id])->first(['id', 'status', 'deleted_at']);
        if ($membership === null) {
            DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $platform, 'user_id' => $user->id, 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        } elseif ($membership->status !== 'active' || $membership->deleted_at !== null) {
            DB::table('tenant_memberships')->where('id', $membership->id)->update(['status' => 'active', 'deleted_at' => null, 'left_at' => null, 'updated_at' => now()]);
        }
    }

    /** @param callable(string): void $change */
    private function locked(callable $change): void
    {
        DB::transaction(function () use ($change) {
            $platform = (string) AccessContext::platformTenantId();
            DB::table('tenants')->where('id', $platform)->lockForUpdate()->value('id');
            $change($platform);
        });
    }
}
