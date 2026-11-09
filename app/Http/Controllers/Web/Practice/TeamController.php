<?php

namespace App\Http\Controllers\Web\Practice;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TeamService;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = (string) AccessContext::currentTenantId();

        $members = DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $tenant)->where('m.status', 'active')->whereNull('m.deleted_at')
            ->orderBy('u.name')->get(['m.id', 'm.user_id', 'm.designation', 'u.name', 'u.email'])
            ->map(function ($member) use ($tenant) {
                $member->roles = DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')
                    ->where(['r.team_id' => $tenant, 'r.model_id' => $member->user_id])->pluck('roles.name')->implode(', ');

                return $member;
            });

        $grantable = array_merge(
            AccessContext::tenantCan($user, 'tenant.roles.assign_standard') ? TeamService::STANDARD_ROLES : [],
            AccessContext::tenantCan($user, 'tenant.roles.assign_privileged') ? TeamService::PRIVILEGED_ROLES : [],
        );

        return view('practice.team', [
            'members' => $members,
            'grantable' => $grantable,
            'canInvite' => AccessContext::tenantCan($user, 'tenant.members.invite'),
            'canDeactivate' => AccessContext::tenantCan($user, 'tenant.members.deactivate'),
            'invitations' => AccessContext::tenantCan($user, 'tenant.members.invite')
                ? DB::table('tenant_invitations')->where('tenant_id', $tenant)->whereNotNull('pending_email')->where('expires_at', '>', now())->get(['id', 'email', 'role_name', 'expires_at'])
                : collect(),
        ]);
    }

    public function invite(Request $request, TeamService $team): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(AccessContext::tenantCan($user, 'tenant.members.invite'), 403);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'role' => ['required', 'string']]);
        $team->invite($user, (string) AccessContext::currentTenantId(), $data['email'], $data['role']);

        return back()->with('status', 'Invitation sent.');
    }

    public function revokeInvitation(Request $request, string $invitation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(AccessContext::tenantCan($user, 'tenant.members.invite'), 403);
        DB::table('tenant_invitations')->where(['id' => $invitation, 'tenant_id' => AccessContext::currentTenantId()])->whereNull('accepted_at')->update(['revoked_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Invitation revoked.');
    }

    public function assignRole(Request $request, User $member, TeamService $team): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['role' => ['required', 'string', Rule::in(array_merge(TeamService::STANDARD_ROLES, TeamService::PRIVILEGED_ROLES))]]);
        $team->assignRole($user, (string) AccessContext::currentTenantId(), $member, $data['role']);

        return back()->with('status', 'Role updated.');
    }

    public function deactivate(Request $request, User $member, TeamService $team): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(AccessContext::tenantCan($user, 'tenant.members.deactivate'), 403);
        abort_unless(AccessContext::isActiveMember($member, AccessContext::currentTenantId()), 404);
        $team->deactivate($user, (string) AccessContext::currentTenantId(), $member);

        return back()->with('status', 'Member removed.');
    }
}
