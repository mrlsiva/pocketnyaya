<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Staff\ComplianceController;
use App\Models\Client;
use App\Models\Matter;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function citizen(): View
    {
        return view('dashboards.citizen');
    }

    public function practice(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $me = (string) MatterAccess::me($user);

        return view('dashboards.practice', [
            'tenant' => Tenant::query()->findOrFail(AccessContext::currentTenantId()),
            'stats' => [
                'matters' => MatterAccess::visibleMatters(Matter::query(), $user)->count(),
                'clients' => MatterAccess::visibleClients(Client::query(), $user)->count(),
                'hearings' => DB::table('hearing_attendees as a')->join('hearings as h', 'h.id', '=', 'a.hearing_id')
                    ->where('a.membership_id', $me)->whereNull('h.deleted_at')->where('h.status', 'scheduled')
                    ->whereBetween('h.hearing_date', [now()->toDateString(), now()->addDays(14)->toDateString()])->count(),
                'tasks' => DB::table('matter_tasks')->where('assigned_to_membership_id', $me)->whereNull('deleted_at')->whereNotIn('status', ['done', 'cancelled'])->count(),
            ],
        ]);
    }

    public function staff(Request $request): View
    {
        return view('dashboards.staff', ['user' => $request->user()]);
    }

    public function tenantAudit(Request $request): View
    {
        return view('audit.index', ComplianceController::auditView($request, AccessContext::currentTenantId()) + ['title' => 'Audit log']);
    }
}
