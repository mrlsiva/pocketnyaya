<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AccessContext;
use App\Support\Isolation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant context (D5.1, D7.2). API requests name their tenant in X-Tenant-ID and
 * the server stores nothing. Web requests use the tenant chosen in the session.
 * The platform tenant is always rejected here.
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $isApi = $request->is('api/*');

        if ($isApi) {
            $tenant = $request->header('X-Tenant-ID');
            if (! is_string($tenant) || ! Str::isUuid($tenant)) {
                abort(400, 'A valid X-Tenant-ID header is required.');
            }
        } else {
            $tenant = $request->session()->get('active_tenant_id');
            if (! is_string($tenant)) {
                return redirect()->route('workspaces.index');
            }
        }

        $isPractice = DB::table('tenants')->where('id', $tenant)->whereIn('type', ['solo', 'firm'])->exists();

        if (! $isPractice || ! AccessContext::isActiveMember($user, $tenant)) {
            if ($isApi) {
                abort(403, 'You do not have access to this workspace.');
            }
            $request->session()->forget('active_tenant_id');

            return redirect()->route('workspaces.index')->withErrors(['workspace' => 'You no longer have access to that workspace.']);
        }

        setPermissionsTeamId($tenant);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $request->attributes->set('pn_context', 'tenant');

        if (! Isolation::enabled()) {
            return $next($request);
        }

        // C52: the database itself now limits this connection to this practice.
        Isolation::bindTenant($user->id, $tenant);
        try {
            return $next($request);
        } finally {
            Isolation::clear();
        }
    }
}
