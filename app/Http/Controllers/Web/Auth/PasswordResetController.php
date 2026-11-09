<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecurityRevocationService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function requestForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        // The same reply either way, so this can't reveal who has an account.
        Password::sendResetLink(['email' => mb_strtolower(trim($data['email']))]);

        return back()->with('status', 'If that email has an account, a reset link is on its way.');
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(Request $request, SecurityRevocationService $revocation): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);
        $data['email'] = mb_strtolower(trim($data['email']));

        $status = Password::reset($data, function (User $user, string $password) use ($revocation) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $revocation->revokeAll($user);
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Your password has been reset. Please sign in.')
            : back()->withErrors(['email' => 'This reset link is invalid or has expired.']);
    }
}
