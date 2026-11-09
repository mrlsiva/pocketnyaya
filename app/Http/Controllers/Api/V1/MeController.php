<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->payload($user));
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{10,14}$/', Rule::unique('users', 'phone')->ignore($user->id)],
            'locale' => ['sometimes', 'string', Rule::in(['en', 'ta', 'hi'])],
        ]);
        $user->update($data);

        return response()->json($this->payload($user));
    }

    public function workspaces(Request $request, WorkspaceService $workspaces): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $list = array_values(array_filter($workspaces->forUser($user), fn (array $w) => $w['kind'] !== 'staff'));

        return response()->json(['data' => $list]);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
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
