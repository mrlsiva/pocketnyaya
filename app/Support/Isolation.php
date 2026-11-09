<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The C52 isolation switch (owner decision 2026-11-09: two connections). When on, the main
 * connection reads every table through its filtered v_ view as pn_app, and each request binds
 * who is asking. Staff screens, console jobs and the practice export use the platform connection.
 */
final class Isolation
{
    /** The limited connection. Binding always targets it, even after a request switches to platform. */
    public const CONNECTION = 'mariadb';

    public static function enabled(): bool
    {
        return (bool) config('pocketnyaya.db_isolation');
    }

    public static function bindUser(string $userId): void
    {
        DB::connection(self::CONNECTION)->statement('CALL pn_bind_user_context(?, ?)', [$userId, self::ttl()]);
    }

    public static function bindTenant(string $userId, string $tenantId): void
    {
        DB::connection(self::CONNECTION)->statement('CALL pn_bind_context(?, ?, ?)', [$userId, $tenantId, self::ttl()]);
    }

    public static function clear(): void
    {
        DB::connection(self::CONNECTION)->statement('CALL pn_clear_context()');
    }

    /** For work that must read across practices: staff screens, jobs and the export. */
    public static function usePlatform(): void
    {
        DB::setDefaultConnection('platform');
    }

    private static function ttl(): int
    {
        return (int) config('pocketnyaya.db_context_ttl', 900);
    }
}
