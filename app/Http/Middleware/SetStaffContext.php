<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff context (D5.1): web only, an active platform membership, a staff role,
 * and MFA completed in this session.
 */
class SetStaffContext
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! AccessContext::isStaff($user)) {
            abort(403);
        }

        if ($user->two_factor_secret === null) {
            return redirect()->route('mfa.setup');
        }
        if ($request->session()->get('mfa_passed_for') !== $user->id) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('mfa.challenge');
        }

        setPermissionsTeamId(AccessContext::platformTenantId());
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $request->attributes->set('pn_context', 'staff');

        return $next($request);
    }
}
