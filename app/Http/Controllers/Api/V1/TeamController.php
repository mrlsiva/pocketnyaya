<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TeamService;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The practice team for mobile. Same services and rules as the web screen (D9). */
class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $tenant = (string) AccessContext::currentTenantId();
        $members = DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $tenant)->where('m.status', 'active')->whereNull('m.deleted_at')
            ->orderBy('u.name')->get(['m.user_id as id', 'u.name', 'u.email', 'm.designation'])
            ->map(function ($member) use ($tenant) {
                $member->roles = DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')
                    ->where(['r.team_id' => $tenant, 'r.model_id' => $member->id])->pluck('roles.name')->all();

                return $member;
            });

        return response()->json([
            'members' => $members,
            'grantable_roles' => array_merge(
                AccessContext::tenantCan($user, 'tenant.roles.assign_standard') ? TeamService::STANDARD_ROLES : [],
                AccessContext::tenantCan($user, 'tenant.roles.assign_privileged') ? TeamService::PRIVILEGED_ROLES : [],
            ),
            'invitations' => AccessContext::tenantCan($user, 'tenant.members.invite')
                ? DB::table('tenant_invitations')->where('tenant_id', $tenant)->whereNotNull('pending_email')->where('expires_at', '>', now())->get(['id', 'email', 'role_name', 'expires_at'])
                : [],
        ]);
    }

    public function invite(Request $request, TeamService $team): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::tenantCan($user, 'tenant.members.invite'), 403);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'role' => ['required', 'string']]);
        $team->invite($user, (string) AccessContext::currentTenantId(), $data['email'], $data['role']);

        return response()->json(['message' => 'Invitation sent.'], 201);
    }

    public function revokeInvitation(Request $request, string $invitation): JsonResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'tenant.members.invite'), 403);
        $updated = DB::table('tenant_invitations')->where(['id' => $invitation, 'tenant_id' => AccessContext::currentTenantId()])
            ->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        abort_if($updated === 0, 404);

        return response()->json(null, 204);
    }

    public function assignRole(Request $request, User $member, TeamService $team): JsonResponse
    {
        abort_unless(AccessContext::isActiveMember($member, AccessContext::currentTenantId()), 404);
        $data = $request->validate(['role' => ['required', 'string', Rule::in(array_merge(TeamService::STANDARD_ROLES, TeamService::PRIVILEGED_ROLES))]]);
        $team->assignRole($this->user($request), (string) AccessContext::currentTenantId(), $member, $data['role']);

        return response()->json(['message' => 'Role updated.']);
    }

    public function deactivate(Request $request, User $member, TeamService $team): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::tenantCan($user, 'tenant.members.deactivate'), 403);
        abort_unless(AccessContext::isActiveMember($member, AccessContext::currentTenantId()), 404);
        $team->deactivate($user, (string) AccessContext::currentTenantId(), $member);

        return response()->json(null, 204);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
