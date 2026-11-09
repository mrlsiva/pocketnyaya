<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function show(string $token): View
    {
        $invitation = DB::table('tenant_invitations as i')->join('tenants as t', 't.id', '=', 'i.tenant_id')
            ->where('i.token_hash', hash('sha256', $token))->first(['i.role_name', 't.name', 'i.accepted_at', 'i.revoked_at', 'i.expires_at']);

        abort_if($invitation === null || $invitation->accepted_at !== null || $invitation->revoked_at !== null || now()->greaterThan($invitation->expires_at), 410);

        return view('invitations.show', ['invitation' => $invitation, 'token' => $token]);
    }

    public function accept(Request $request, string $token, TeamService $team): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $team->accept($user, $token);

        $request->session()->regenerate();
        $request->session()->put('active_tenant_id', $tenant);

        return redirect()->route('practice.dashboard')->with('status', 'Welcome to the team.');
    }
}
