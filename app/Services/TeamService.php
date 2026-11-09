<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Firm membership and roles (D8.2, D9). DA-18: a Firm Admin grants standard
 * roles, and only a Managing Partner grants MP or FA. No self-elevation.
 */
class TeamService
{
    public const STANDARD_ROLES = ['Senior Associate', 'Junior Associate', 'Finance Manager', 'Clerk / Office Staff'];

    public const PRIVILEGED_ROLES = ['Managing Partner', 'Firm Admin'];

    /** DA-7 */
    private const NEEDS_ADVOCATE_PROFILE = ['Managing Partner', 'Senior Associate', 'Junior Associate'];

    public function __construct(private RoleAssignmentService $roles) {}

    public function invite(User $inviter, string $tenantId, string $email, string $role): string
    {
        $this->guardCanGrant($inviter, $tenantId, $role);

        if (DB::table('tenants')->where('id', $tenantId)->value('type') !== 'firm') {
            throw ValidationException::withMessages(['email' => 'A solo practice cannot invite members.']);
        }

        $email = mb_strtolower(trim($email));
        $isMember = DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $tenantId)->where('u.email', $email)->where('m.status', 'active')->whereNull('m.deleted_at')->exists();
        $isPending = DB::table('tenant_invitations')->where(['tenant_id' => $tenantId, 'pending_email' => $email])->exists();
        if ($isMember || $isPending) {
            throw ValidationException::withMessages(['email' => 'That person is already a member or has a pending invitation.']);
        }

        $token = Str::random(48);
        DB::table('tenant_invitations')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'email' => $email, 'role_name' => $role,
            'invited_by' => $inviter->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Notification::route('mail', $email)->notify(new TenantInvitationNotification($token, (string) DB::table('tenants')->where('id', $tenantId)->value('name'), $role));

        return $token;
    }

    public function accept(User $user, string $token): string
    {
        $invitation = DB::table('tenant_invitations')->where('token_hash', hash('sha256', $token))->first();

        if ($invitation === null || $invitation->accepted_at !== null || $invitation->revoked_at !== null || now()->greaterThan($invitation->expires_at)) {
            abort(410, 'This invitation is no longer valid.');
        }
        if ($invitation->email !== $user->email) {
            throw ValidationException::withMessages(['invitation' => 'This invitation was sent to a different email address.']);
        }

        // D8.2: the inviter's right to grant this role is checked again at acceptance.
        $inviter = User::query()->findOrFail($invitation->invited_by);
        $permission = in_array($invitation->role_name, self::PRIVILEGED_ROLES, true) ? 'tenant.roles.assign_privileged' : 'tenant.roles.assign_standard';
        if (! AccessContext::isActiveMember($inviter, $invitation->tenant_id) || ! AccessContext::hasPermissionIn($inviter, $permission, $invitation->tenant_id)) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is no longer valid.']);
        }
        $this->guardAdvocateProfile($user, $invitation->role_name);

        DB::transaction(function () use ($user, $invitation) {
            DB::table('tenant_memberships')->updateOrInsert(
                ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
                ['id' => (string) Str::uuid7(), 'status' => 'active', 'joined_at' => now(), 'left_at' => null, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()]
            );
            $this->roles->assign($user, $invitation->role_name, $invitation->tenant_id);
            DB::table('tenant_invitations')->where('id', $invitation->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
        });

        return (string) $invitation->tenant_id;
    }

    public function assignRole(User $actor, string $tenantId, User $target, string $role): void
    {
        $this->guardCanGrant($actor, $tenantId, $role);
        if ($actor->id === $target->id) {
            abort(403, 'You cannot change your own role.');
        }
        if (! AccessContext::isActiveMember($target, $tenantId)) {
            throw ValidationException::withMessages(['role' => 'That person is not an active member.']);
        }
        $this->guardAdvocateProfile($target, $role);

        $this->roles->assign($target, $role, $tenantId);
    }

    public function deactivate(User $actor, string $tenantId, User $target): void
    {
        if ($this->holds($actor, $tenantId, 'Firm Admin') && ! $this->holds($actor, $tenantId, 'Managing Partner')
            && ($this->holds($target, $tenantId, 'Managing Partner') || $this->holds($target, $tenantId, 'Firm Admin'))) {
            abort(403, 'A Firm Admin cannot remove a Managing Partner or another Firm Admin.');
        }
        if ($this->holds($target, $tenantId, 'Managing Partner') && $this->activePartners($tenantId) <= 1) {
            throw ValidationException::withMessages(['member' => 'A firm must keep at least one active Managing Partner.']);
        }

        // The database trigger removes this member's roles in the same transaction.
        DB::table('tenant_memberships')->where(['tenant_id' => $tenantId, 'user_id' => $target->id])
            ->update(['status' => 'left', 'left_at' => now(), 'updated_at' => now()]);
    }

    private function guardCanGrant(User $actor, string $tenantId, string $role): void
    {
        if (in_array($role, self::PRIVILEGED_ROLES, true)) {
            abort_unless(AccessContext::can($actor, 'tenant.roles.assign_privileged', $tenantId), 403);
        } elseif (in_array($role, self::STANDARD_ROLES, true)) {
            abort_unless(AccessContext::can($actor, 'tenant.roles.assign_standard', $tenantId), 403);
        } else {
            throw ValidationException::withMessages(['role' => 'That role cannot be granted here.']);
        }
    }

    private function guardAdvocateProfile(User $user, string $role): void
    {
        if (in_array($role, self::NEEDS_ADVOCATE_PROFILE, true) && $user->advocateProfile()->doesntExist()) {
            throw ValidationException::withMessages(['role' => 'This role needs an advocate profile first.']);
        }
    }

    private function holds(User $user, string $tenantId, string $role): bool
    {
        return DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')
            ->where(['r.team_id' => $tenantId, 'r.model_id' => $user->id, 'roles.name' => $role])->exists();
    }

    private function activePartners(string $tenantId): int
    {
        return DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')
            ->join('tenant_memberships as m', function ($join) {
                $join->on('m.user_id', '=', 'r.model_id')->on('m.tenant_id', '=', 'r.team_id');
            })
            ->where('r.team_id', $tenantId)->where('roles.name', 'Managing Partner')->where('m.status', 'active')->count();
    }
}
