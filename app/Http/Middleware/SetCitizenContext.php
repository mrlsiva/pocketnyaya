<?php

namespace App\Http\Middleware;

use App\Support\AccessContext;
use App\Support\Isolation;
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

        $user = $request->user();
        if (! Isolation::enabled() || $user === null) {
            return $next($request);
        }

        // C52: personal-vault and customer views now see only this person's rows.
        Isolation::bindUser((string) $user->getAuthIdentifier());
        try {
            return $next($request);
        } finally {
            Isolation::clear();
        }
    }
}
