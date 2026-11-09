<?php

namespace App\Http\Middleware;

use App\Support\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        if (! config('pocketnyaya.db_isolation') || $user === null) {
            return $next($request);
        }

        // C52: personal-vault and customer views now see only this person's rows.
        DB::statement('CALL pn_bind_user_context(?, ?)', [$user->getAuthIdentifier(), (int) config('pocketnyaya.db_context_ttl')]);
        try {
            return $next($request);
        } finally {
            DB::statement('CALL pn_clear_context()');
        }
    }
}
