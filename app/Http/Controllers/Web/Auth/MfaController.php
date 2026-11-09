<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MfaService;
use App\Services\SecurityRevocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MfaController extends Controller
{
    public function setupForm(Request $request, MfaService $mfa): View
    {
        $secret = $request->session()->get('mfa_pending_secret');
        if (! is_string($secret)) {
            $secret = $mfa->generateSecret();
            $request->session()->put('mfa_pending_secret', $secret);
        }

        return view('auth.mfa-setup', ['secret' => $secret]);
    }

    public function setup(Request $request, MfaService $mfa, SecurityRevocationService $revocation): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $secret = (string) $request->session()->get('mfa_pending_secret');

        if ($secret === '' || ! $mfa->verify($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['two_factor_secret' => $secret])->save();
        $revocation->revokeOthers($user, $request->session()->getId());
        $request->session()->forget('mfa_pending_secret');
        $request->session()->put('mfa_passed_for', $user->id);
        $request->session()->regenerate();

        return redirect()->route('staff.dashboard');
    }

    public function challengeForm(): View
    {
        return view('auth.mfa-challenge');
    }

    public function challenge(Request $request, MfaService $mfa): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_secret === null || ! $mfa->verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }

        $request->session()->put('mfa_passed_for', $user->id);
        $request->session()->regenerate();

        return redirect()->intended(route('staff.dashboard'));
    }
}
