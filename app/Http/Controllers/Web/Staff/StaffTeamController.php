<?php

namespace App\Http\Controllers\Web\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StaffTeamService;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** D9: platform staff roles. Super Admin needs a proposal and a second Super Admin's approval. */
class StaffTeamController extends Controller
{
    public function __construct(private StaffTeamService $team) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $platform = AccessContext::platformTenantId();

        $staff = DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')->join('users as u', 'u.id', '=', 'r.model_id')
            ->where('r.team_id', $platform)->whereIn('roles.name', ['Super Admin', 'Compliance Officer'])
            ->orderBy('u.name')->get(['u.id', 'u.name', 'u.email', 'u.status', 'roles.name as role']);

        return view('staff.admin.team', [
            'staff' => $staff,
            'proposals' => $this->team->openProposals(),
            'superAdmins' => $this->team->activeSuperAdminCount(),
            'minimum' => StaffTeamService::MIN_SUPER_ADMINS,
            'canAssign' => AccessContext::staffCan($user, 'platform.staff_roles.assign'),
            'canPropose' => AccessContext::staffCan($user, 'platform.superadmin.propose'),
            'canApprove' => AccessContext::staffCan($user, 'platform.superadmin.approve'),
            'me' => $user->id,
        ]);
    }

    public function complianceOfficer(Request $request): RedirectResponse
    {
        abort_unless(AccessContext::staffCan($this->user($request), 'platform.staff_roles.assign'), 403);
        $data = $request->validate(['email' => ['required', 'email'], 'action' => ['required', Rule::in(['grant', 'revoke'])]]);
        $target = $this->target($data['email']);

        $data['action'] === 'grant'
            ? $this->team->grantComplianceOfficer($this->user($request), $target)
            : $this->team->revokeComplianceOfficer($this->user($request), $target);

        return back()->with('status', $data['action'] === 'grant' ? 'Compliance Officer role granted.' : 'Compliance Officer role removed.');
    }

    public function propose(Request $request): RedirectResponse
    {
        abort_unless(AccessContext::staffCan($this->user($request), 'platform.superadmin.propose'), 403);
        $data = $request->validate(['email' => ['required', 'email']]);
        $this->team->proposeSuperAdmin($this->user($request), $this->target($data['email']));

        return back()->with('status', 'Proposal recorded. A different Super Admin must approve it.');
    }

    public function decide(Request $request, User $person): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'withdraw'])]]);
        if ($data['decision'] === 'approve') {
            abort_unless(AccessContext::staffCan($user, 'platform.superadmin.approve'), 403);
            $this->team->approveSuperAdmin($user, $person);

            return back()->with('status', 'Super Admin approved.');
        }
        abort_unless(AccessContext::staffCan($user, 'platform.superadmin.propose'), 403);
        $this->team->withdrawProposal($user, $person);

        return back()->with('status', 'Proposal withdrawn.');
    }

    public function revokeSuperAdmin(Request $request, User $person): RedirectResponse
    {
        abort_unless(AccessContext::staffCan($this->user($request), 'platform.superadmin.approve'), 403);
        $this->team->revokeSuperAdmin($this->user($request), $person);

        return back()->with('status', 'Super Admin role removed.');
    }

    /** Looks up an account by email. A missing account gives the same error as an invalid one. */
    private function target(string $email): User
    {
        $target = User::query()->where('email', mb_strtolower(trim($email)))->whereNull('erased_at')->first();
        if ($target === null) {
            throw ValidationException::withMessages(['email' => 'No active account uses that email.']);
        }

        return $target;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
