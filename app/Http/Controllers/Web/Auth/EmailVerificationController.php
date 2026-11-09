<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $user->hasVerifiedEmail() ? redirect()->route('workspaces.index') : view('auth.verify-email');
    }

    /** The signed link works from any device, so mobile users don't need a browser session. */
    public function verify(string $id, string $hash): RedirectResponse
    {
        $user = User::query()->findOrFail($id);
        abort_unless(hash_equals(sha1((string) $user->getEmailForVerification()), $hash), 403);

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->route('login')->with('status', 'Your email is verified. Please sign in.');
    }

    public function resend(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', 'A new verification link has been sent.');
    }
}
