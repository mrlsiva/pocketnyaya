<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Token and session revocation (D7.2 item 5).
 */
class SecurityRevocationService
{
    /** A signed-in password change or MFA change. The current session survives. */
    public function revokeOthers(User $user, ?string $currentSessionId): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)
            ->when($currentSessionId !== null, fn ($q) => $q->where('id', '<>', $currentSessionId))
            ->delete();
    }

    /** A password reset while signed out, suspension, or erasure. */
    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
