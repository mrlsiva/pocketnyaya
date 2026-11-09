<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecurityRevocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,14}$/', Rule::unique('users', 'phone')->ignore($user->id)],
            'locale' => ['required', 'string', Rule::in(['en', 'ta', 'hi'])],
        ]);
        $user->update($data);

        return back()->with('status', 'Your profile has been updated.');
    }

    public function password(Request $request, SecurityRevocationService $revocation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        if (! Hash::check($data['current_password'], (string) $user->password)) {
            return back()->withErrors(['current_password' => 'Your current password is not correct.']);
        }

        $request->session()->regenerate();
        $user->update(['password' => $data['password']]);
        $revocation->revokeOthers($user, $request->session()->getId());

        return back()->with('status', 'Your password has been changed, and other devices have been signed out.');
    }
}
