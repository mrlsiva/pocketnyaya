<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prints the SQL for the two application accounts (owner decision 2026-11-09), built from the
 * live schema. It prints only, and runs nothing. Passwords are placeholders that the database
 * administrator replaces privately.
 *
 * - pn_app: the views only (the app's v_ prefix), and EXECUTE on the context procedures.
 *   No base table, so every firm row it can reach is filtered to the bound practice or person.
 * - pn_platform: data access to the base tables for staff screens, jobs and the export. No DDL.
 *
 * Migrations stay with the administrator account (C56), which is the only one listed in
 * pn_privileged_accounts. Neither application account may ever be added to that table.
 */
class IsolationGrants extends Command
{
    protected $signature = 'pocketnyaya:isolation-grants {--host=localhost : The host part of both accounts}';

    protected $description = 'Prints the pn_app and pn_platform accounts and grants (prints only)';

    /** Views from the first layer that combine scopes: for reading only. */
    private const READ_ONLY_VIEWS = ['v_effective_tax_rules', 'v_tenant_audit'];

    /** Append-only tables (C19): rows may be added, never changed. */
    private const APPEND_ONLY = ['audit_logs', 'document_access_logs', 'consent_records', 'erasure_actions', 'disclaimer_acceptances', 'content_screenings', 'odr_screenings', 'odr_messages', 'consultation_messages', 'document_versions'];

    /** Neither account touches these directly. */
    private const HIDDEN = ['pn_access_context', 'pn_privileged_accounts', 'migrations'];

    public function handle(): int
    {
        $db = DB::connection('platform')->getDatabaseName();
        $host = (string) $this->option('host');
        $app = "'pn_app'@'{$host}'";
        $platform = "'pn_platform'@'{$host}'";
        $lines = [
            '-- PocketNyaya application accounts. Generated '.now()->toDateTimeString()." from database `{$db}`.",
            '-- Replace both password placeholders privately before running. Never paste them into chat or a report.',
            '-- Run as the database administrator. Then set DB_USERNAME=pn_app, PLATFORM_DB_USERNAME=pn_platform and DB_ISOLATION=true.',
            "CREATE USER IF NOT EXISTS {$app} IDENTIFIED BY 'REPLACE_WITH_A_STRONG_PASSWORD_FOR_PN_APP';",
            "CREATE USER IF NOT EXISTS {$platform} IDENTIFIED BY 'REPLACE_WITH_A_STRONG_PASSWORD_FOR_PN_PLATFORM';",
        ];

        foreach (DB::connection('platform')->select("SELECT TABLE_NAME n FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'v\\_%' ORDER BY 1", [$db]) as $view) {
            $base = substr($view->n, 2);
            $privileges = match (true) {
                in_array($view->n, self::READ_ONLY_VIEWS, true) => 'SELECT',
                in_array($base, self::APPEND_ONLY, true) => 'SELECT, INSERT',
                default => 'SELECT, INSERT, UPDATE, DELETE',
            };
            $lines[] = "GRANT {$privileges} ON `{$db}`.`{$view->n}` TO {$app};";
        }
        foreach (['pn_bind_context', 'pn_bind_user_context', 'pn_clear_context', 'pn_assert_context'] as $procedure) {
            $lines[] = "GRANT EXECUTE ON PROCEDURE `{$db}`.`{$procedure}` TO {$app};";
        }

        foreach (DB::connection('platform')->select("SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1", [$db]) as $table) {
            if (in_array($table->n, self::HIDDEN, true)) {
                continue;
            }
            $privileges = in_array($table->n, self::APPEND_ONLY, true) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE';
            $lines[] = "GRANT {$privileges} ON `{$db}`.`{$table->n}` TO {$platform};";
        }

        $this->line(implode(PHP_EOL, $lines));

        return self::SUCCESS;
    }
}
