<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\SecurityRevocationService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegistrationService $registration): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, phone?: string|null} $data */
        $data = $request->validated();
        $user = $registration->register($data, 'api');

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $this->issueToken($user, (string) $request->input('device_name', 'mobile')),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', mb_strtolower(trim($data['email'])))->first();

        if ($user === null || $user->status !== 'active' || ! Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['email' => 'These details do not match an active account.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $this->issueToken($user, $data['device_name'] ?? 'mobile'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => mb_strtolower(trim($data['email']))]);

        return response()->json(['message' => 'If that email has an account, a reset link is on its way.']);
    }

    public function resetPassword(Request $request, SecurityRevocationService $revocation): JsonResponse
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

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired.']);
        }

        return response()->json(['message' => 'Your password has been reset.']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If your email is unverified, a new link is on its way.']);
    }

    private function issueToken(User $user, string $device): string
    {
        // DA-12: a user-level token for first-party mobile apps, valid for 14 days.
        return $user->createToken($device, ['api:read', 'api:write'], now()->addDays((int) config('pocketnyaya.token_days')))->plainTextToken;
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }
}
