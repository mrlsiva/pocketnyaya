<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honours a permission only when its kind matches the request's context (D5.1).
 * Usage: pn.can:clients.create or pn.can:clients.view_all|clients.view_assigned
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        /** @var User $user */
        $user = $request->user();
        $context = $request->attributes->get('pn_context');

        foreach (explode('|', $permissions) as $permission) {
            $allowed = AccessContext::kindOf($permission) === $context && match ($context) {
                'citizen' => AccessContext::citizenCan($user, $permission),
                'staff' => AccessContext::staffCan($user, $permission),
                'tenant' => AccessContext::tenantCan($user, $permission),
                default => false,
            };
            if ($allowed) {
                return $next($request);
            }
        }

        abort(403);
    }
}
