<?php

namespace App\Support;

use App\Events\RealtimeUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends real-time signals. They are best effort: if the Reverb server isn't running, the
 * signal is dropped and logged, and the user's action still succeeds. Screens can always be
 * refreshed by hand, so nothing depends on a signal arriving.
 */
final class Realtime
{
    /** @param  array<string, string|int|null>  $data */
    public static function toUser(?string $userId, string $type, array $data = []): void
    {
        if ($userId !== null && $userId !== '') {
            self::send("users.{$userId}", $type, $data);
        }
    }

    /**
     * Firm diary changes, for members who may see hearings.
     *
     * @param  array<string, string|int|null>  $data
     */
    public static function toTenantDiary(string $tenantId, string $type, array $data = []): void
    {
        self::send("tenants.{$tenantId}.diary", $type, $data);
    }

    /** @param  array<string, string|int|null>  $data */
    private static function send(string $channel, string $type, array $data): void
    {
        if (in_array(config('broadcasting.default'), ['null', 'log'], true)) {
            return;
        }
        // Only once the change is saved, so a rolled-back action never signals.
        DB::afterCommit(function () use ($channel, $type, $data) {
            try {
                event(new RealtimeUpdate($channel, $type, $data));
            } catch (Throwable $e) {
                Log::warning('Real-time update not sent: '.$e->getMessage(), ['type' => $type]);
            }
        });
    }
}
