<?php

namespace App\Http\Middleware;

use App\Support\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Citizen context: the platform tenant, with no membership needed. The tenant
 * header is deliberately ignored here (D7.2).
 */
class SetCitizenContext
{
    public function handle(Request $request, Closure $next): Response
    {
        setPermissionsTeamId(AccessContext::platformTenantId());
        $request->attributes->set('pn_context', 'citizen');

        return $next($request);
    }
}
