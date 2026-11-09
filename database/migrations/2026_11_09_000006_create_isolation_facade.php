<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Isolation switch-over (owner decision 2026-11-09: two connections).
 *
 * Every base table gets a same-named v_ view, so the limited connection only needs Laravel's
 * table prefix "v_" and no query changes. Firm tables are filtered to the bound practice, and
 * mixed tables by scope, owner and share. Other tables pass straight through (T4, application-
 * controlled, as accepted in C52).
 *
 * Accounts named in pn_privileged_accounts see every row through the views: the migration or
 * administrator account, so tests and deployments work. pn_app must never be listed there, and
 * it has no rights on that table. Staff screens, jobs and the practice export use the separate
 * platform connection on the base tables.
 */
return new class extends Migration
{
    private const PRIVILEGED = 'EXISTS (SELECT 1 FROM pn_privileged_accounts pa WHERE pa.account_name = SUBSTRING_INDEX(USER(), \'@\', 1))';

    private const CTX_TENANT = "(SELECT c.tenant_id FROM pn_access_context c
        JOIN tenant_memberships tm ON tm.id = c.membership_id AND tm.status = 'active' AND tm.deleted_at IS NULL
        JOIN users u ON u.id = c.user_id AND u.status = 'active'
        WHERE c.connection_id = CONNECTION_ID() AND c.kind = 'tenant' AND c.expires_at > UTC_TIMESTAMP(6))";

    private const CTX_TENANT_BOUND_ONLY = "(SELECT c.tenant_id FROM pn_access_context c
        JOIN users u ON u.id = c.user_id AND u.status = 'active'
        WHERE c.connection_id = CONNECTION_ID() AND c.kind = 'tenant' AND c.expires_at > UTC_TIMESTAMP(6))";

    /** The signed-in person, in a practice or a personal context. */
    private const CTX_USER = "(SELECT c.user_id FROM pn_access_context c
        JOIN users u ON u.id = c.user_id AND u.status = 'active'
        WHERE c.connection_id = CONNECTION_ID() AND c.expires_at > UTC_TIMESTAMP(6))";

    private const T1 = [
        'diary_entries', 'fee_arrangements', 'fee_schedule_items', 'hearings', 'hearing_attendees', 'invoices', 'invoice_lines',
        'invoice_series', 'knowledge_items', 'matters', 'matter_assignments', 'matter_expenses', 'matter_notes', 'matter_parties',
        'matter_tasks', 'membership_availability', 'membership_practice_areas', 'payments', 'payment_allocations', 'practice_groups',
        'practice_group_members', 'retainer_plans', 'retainer_work_items', 'schedule_conflicts', 'tenant_branches', 'tenant_invitations',
        'tenant_subscriptions', 'time_entries',
    ];

    /** Never exposed to pn_app, even as a pass-through. */
    private const NO_VIEW = ['pn_access_context', 'pn_privileged_accounts'];

    public function up(): void
    {
        DB::statement('CREATE TABLE pn_privileged_accounts (account_name VARCHAR(80) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        DB::statement("INSERT INTO pn_privileged_accounts VALUES (SUBSTRING_INDEX(USER(), '@', 1))");

        $p = self::PRIVILEGED;
        $tenant = self::CTX_TENANT;
        $user = self::CTX_USER;
        $visibleDocument = "(d.scope = 'tenant' AND d.tenant_id = {$tenant}) OR (d.scope = 'personal' AND d.owner_user_id = {$user}) OR d.scope = 'platform'
            OR EXISTS (SELECT 1 FROM document_shares s WHERE s.document_id = d.id AND s.revoked_at IS NULL AND (s.expires_at IS NULL OR s.expires_at > UTC_TIMESTAMP(6))
                AND (s.grantee_user_id = {$user} OR s.grantee_tenant_id = {$tenant}))";

        $views = [];
        foreach (self::T1 as $table) {
            $views[$table] = "SELECT * FROM {$table} WHERE {$p} OR tenant_id = {$tenant}";
        }
        $views['tenant_memberships'] = "SELECT * FROM tenant_memberships WHERE {$p} OR tenant_id = ".self::CTX_TENANT_BOUND_ONLY." OR user_id = {$user}";
        $views['clients'] = "SELECT * FROM clients WHERE {$p} OR tenant_id = {$tenant} OR customer_user_id = {$user}";
        $views['consultation_requests'] = "SELECT * FROM consultation_requests WHERE {$p} OR tenant_id = {$tenant} OR customer_user_id = {$user}";
        $views['consultation_messages'] = "SELECT * FROM consultation_messages m WHERE {$p} OR EXISTS (SELECT 1 FROM consultation_requests r WHERE r.id = m.consultation_request_id AND (r.tenant_id = {$tenant} OR r.customer_user_id = {$user}))";
        $views['tax_rules'] = "SELECT * FROM tax_rules WHERE {$p} OR tenant_id IS NULL OR tenant_id = {$tenant}";
        $views['documents'] = "SELECT * FROM documents d WHERE {$p} OR {$visibleDocument}";
        $views['document_folders'] = "SELECT * FROM document_folders f WHERE {$p} OR (f.scope = 'tenant' AND f.tenant_id = {$tenant}) OR (f.scope = 'personal' AND f.owner_user_id = {$user}) OR f.scope = 'platform'";
        foreach (['document_versions', 'document_keys' => 'document_version_id'] as $table => $link) {
            if (is_int($table)) {
                [$table, $link] = [$link, 'document_id'];
                $views[$table] = "SELECT * FROM {$table} x WHERE {$p} OR EXISTS (SELECT 1 FROM documents d WHERE d.id = x.{$link} AND ({$visibleDocument}))";
            } else {
                $views[$table] = "SELECT * FROM {$table} x WHERE {$p} OR EXISTS (SELECT 1 FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE v.id = x.{$link} AND ({$visibleDocument}))";
            }
        }
        $views['document_shares'] = "SELECT * FROM document_shares s WHERE {$p} OR s.grantee_user_id = {$user} OR s.grantee_tenant_id = {$tenant}
            OR EXISTS (SELECT 1 FROM documents d WHERE d.id = s.document_id AND ((d.scope = 'tenant' AND d.tenant_id = {$tenant}) OR (d.scope = 'personal' AND d.owner_user_id = {$user})))";

        $base = array_map(fn ($r) => $r->n, DB::select("SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"));
        foreach ($base as $table) {
            if (in_array($table, self::NO_VIEW, true)) {
                continue;
            }
            $sql = $views[$table] ?? "SELECT * FROM {$table}";
            $check = isset($views[$table]) ? ' WITH CASCADED CHECK OPTION' : '';
            DB::statement("CREATE OR REPLACE ALGORITHM = MERGE SQL SECURITY DEFINER VIEW v_{$table} AS {$sql}{$check}");
        }
    }

    public function down(): void
    {
        $base = array_map(fn ($r) => $r->n, DB::select("SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"));
        foreach ($base as $table) {
            if (! in_array($table, self::NO_VIEW, true)) {
                DB::statement("DROP VIEW IF EXISTS v_{$table}");
            }
        }
        DB::statement('DROP TABLE IF EXISTS pn_privileged_accounts');
        // The original per-table views from 2026_11_09_000003 are recreated by rolling that migration back and forward.
    }
};
