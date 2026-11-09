<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tokens carry api:read and api:write. Reads need the first, changes the second (D7.2).
 */
class RequireApiWriteAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $needed = $request->isMethodSafe() ? 'api:read' : 'api:write';

        abort_unless($request->user()?->tokenCan($needed), 403, 'This token does not allow that action.');

        return $next($request);
    }
}
