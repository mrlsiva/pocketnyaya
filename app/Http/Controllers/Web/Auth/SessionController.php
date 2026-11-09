<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $credentials['email'] = mb_strtolower(trim($credentials['email']));

        if (! Auth::attempt($credentials + ['status' => 'active'], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These details do not match an active account.']);
        }

        $request->session()->regenerate();
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('workspaces.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
