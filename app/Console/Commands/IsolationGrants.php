<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prints the SQL that creates the limited pn_app account and its grants (C52, C56), built
 * from the live schema. It prints only, and runs nothing. The password is a placeholder that
 * the database administrator replaces privately. Run the output only after the app reads
 * firm data through the v_* views, or the app will stop working.
 */
class IsolationGrants extends Command
{
    protected $signature = 'pocketnyaya:isolation-grants {--host=localhost : The host part of the pn_app account}';

    protected $description = 'Prints the pn_app account and grants for the tenant-isolation layer (prints only)';

    /** Base tables pn_app must not touch directly: their rows are reached through views. */
    private const VIEW_ONLY = [
        'clients', 'consultation_requests', 'diary_entries', 'fee_arrangements', 'fee_schedule_items', 'hearings', 'hearing_attendees',
        'invoices', 'invoice_lines', 'invoice_series', 'knowledge_items', 'matters', 'matter_assignments', 'matter_expenses',
        'matter_notes', 'matter_parties', 'matter_tasks', 'membership_availability', 'membership_practice_areas', 'payments',
        'payment_allocations', 'practice_groups', 'practice_group_members', 'retainer_plans', 'retainer_work_items',
        'schedule_conflicts', 'tenant_branches', 'tenant_invitations', 'tenant_memberships', 'tenant_subscriptions', 'time_entries',
        'documents', 'document_folders', 'tax_rules', 'pn_access_context', 'migrations',
    ];

    /** Join and combined views: MariaDB may call some of them updatable, but they are for reading. */
    private const READ_ONLY_VIEWS = ['v_document_versions', 'v_consultation_messages', 'v_effective_tax_rules', 'v_tenant_audit'];

    /** Append-only tables: rows may be added, never changed. */
    private const APPEND_ONLY = ['audit_logs', 'document_access_logs', 'consent_records', 'erasure_actions', 'disclaimer_acceptances', 'content_screenings', 'odr_screenings', 'odr_messages'];

    /** Children without tenant_id (T3): written by the app, read only through join views. */
    private const INSERT_ONLY = ['document_versions', 'consultation_messages'];

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();
        $account = "'pn_app'@'".$this->option('host')."'";
        $lines = [
            '-- PocketNyaya pn_app account. Generated '.now()->toDateTimeString()." from database `{$db}`.",
            '-- Replace the password placeholder privately before running. Never paste it into chat or a report.',
            '-- Run as the database administrator, and only after the app has been switched to the v_* views.',
            "CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY 'REPLACE_WITH_A_STRONG_PASSWORD';",
        ];

        foreach (DB::select("SELECT TABLE_NAME n, IS_UPDATABLE u FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'v\\_%' ORDER BY 1", [$db]) as $view) {
            $privileges = $view->u === 'YES' && ! in_array($view->n, self::READ_ONLY_VIEWS, true) ? 'SELECT, INSERT, UPDATE, DELETE' : 'SELECT';
            $lines[] = "GRANT {$privileges} ON `{$db}`.`{$view->n}` TO {$account};";
        }

        foreach (DB::select("SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1", [$db]) as $table) {
            if (in_array($table->n, self::VIEW_ONLY, true)) {
                continue;
            }
            $privileges = match (true) {
                in_array($table->n, self::APPEND_ONLY, true) => 'SELECT, INSERT',
                in_array($table->n, self::INSERT_ONLY, true) => 'INSERT',
                default => 'SELECT, INSERT, UPDATE, DELETE',
            };
            $lines[] = "GRANT {$privileges} ON `{$db}`.`{$table->n}` TO {$account};";
        }

        foreach (['pn_bind_context', 'pn_bind_user_context', 'pn_clear_context', 'pn_assert_context'] as $procedure) {
            $lines[] = "GRANT EXECUTE ON PROCEDURE `{$db}`.`{$procedure}` TO {$account};";
        }

        $this->line(implode(PHP_EOL, $lines));

        return self::SUCCESS;
    }
}
