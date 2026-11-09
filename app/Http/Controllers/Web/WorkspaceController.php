<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WorkspaceService;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request, WorkspaceService $workspaces): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('workspaces.index', ['workspaces' => $workspaces->forUser($user)]);
    }

    /** D7.2: checks the membership and rotates the session id. A failure changes nothing. */
    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'uuid']]);
        /** @var User $user */
        $user = $request->user();

        $isPractice = DB::table('tenants')->where('id', $data['tenant_id'])->whereIn('type', ['solo', 'firm'])->exists();
        abort_unless($isPractice && AccessContext::isActiveMember($user, $data['tenant_id']), 403);

        $request->session()->regenerate();
        $request->session()->put('active_tenant_id', $data['tenant_id']);

        return redirect()->route('practice.dashboard');
    }
}
