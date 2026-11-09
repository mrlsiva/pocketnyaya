<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only way to write audit rows. It runs inside the caller's transaction, so a
 * rollback removes the row too. A trigger computes row_hash. Callers pass only
 * statuses and IDs: never secrets, document contents or personal details.
 */
class AuditLogWriter
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(string $event, ?string $type = null, ?string $id = null, ?string $tenantId = null, ?array $old = null, ?array $new = null, ?User $actor = null): void
    {
        $request = request();
        $actor ??= $request->user();
        $channel = app()->runningInConsole() && ! app()->runningUnitTests() ? 'console' : ($request->is('api/*') ? 'api' : 'web');

        DB::table('audit_logs')->insert([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actor instanceof User ? $actor->id : null,
            'actor_type' => $actor instanceof User ? 'user' : ($channel === 'console' ? 'system' : 'api'),
            'event' => $event,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'old_values' => $old === null ? null : json_encode($old),
            'new_values' => $new === null ? null : json_encode($new),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'channel' => $channel,
        ]);
    }
}
