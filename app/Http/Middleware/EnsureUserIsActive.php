<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Isolation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->status !== 'active') {
            if ($request->is('api/*')) {
                abort(403, 'This account is not active.');
            }
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account is not active.']);
        }

        if (! $user instanceof User || ! Isolation::enabled()) {
            return $next($request);
        }

        // C52: by default a signed-in request sees only this person's own rows. The practice and
        // personal context middleware narrow or replace this binding.
        Isolation::bindUser($user->id);
        try {
            return $next($request);
        } finally {
            Isolation::clear();
        }
    }
}
