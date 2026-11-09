<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * C52/C53 tenant-isolation layer, built for MariaDB (owner decision 2026-11-09) and
 * switched off by default. It protects data only once the app connects as the limited
 * pn_app account, which may read and write firm tables through these views alone
 * (database/sql/isolation-accounts.sql). Until then the app keeps using the tables directly.
 *
 * - pn_access_context: one row per database connection, bound by the definer procedures.
 * - v_<table>: every firm table, filtered to the bound practice, WITH CASCADED CHECK OPTION
 *   so a row can't be written into another practice.
 * - Personal-vault, customer and platform splits for mixed-scope tables (T2).
 * - Read-only join views for child tables without tenant_id (T3).
 *
 * Differences from the MySQL design, for review: MariaDB has no FOR SHARE; the procedures
 * don't check the caller's account name, because EXECUTE is granted to pn_app alone;
 * and the T3 write procedures aren't built yet.
 */
return new class extends Migration
{
    /** T1: firm tables whose tenant_id is never NULL. */
    private const TENANT_TABLES = [
        'clients', 'consultation_requests', 'diary_entries', 'fee_arrangements', 'fee_schedule_items', 'hearings', 'hearing_attendees',
        'invoices', 'invoice_lines', 'invoice_series', 'knowledge_items', 'matters', 'matter_assignments', 'matter_expenses',
        'matter_notes', 'matter_parties', 'matter_tasks', 'membership_availability', 'membership_practice_areas', 'payments',
        'payment_allocations', 'practice_groups', 'practice_group_members', 'retainer_plans', 'retainer_work_items',
        'schedule_conflicts', 'tenant_branches', 'tenant_invitations', 'tenant_memberships', 'tenant_subscriptions', 'time_entries',
    ];

    private const TENANT_PREDICATE = "(SELECT c.tenant_id FROM pn_access_context c
        JOIN tenant_memberships tm ON tm.id = c.membership_id AND tm.status = 'active' AND tm.deleted_at IS NULL
        JOIN users u ON u.id = c.user_id AND u.status = 'active'
        WHERE c.connection_id = CONNECTION_ID() AND c.kind = 'tenant' AND c.expires_at > UTC_TIMESTAMP(6))";

    private const TENANT_PREDICATE_BOUND_ONLY = "(SELECT c.tenant_id FROM pn_access_context c
        JOIN users u ON u.id = c.user_id AND u.status = 'active'
        WHERE c.connection_id = CONNECTION_ID() AND c.kind = 'tenant' AND c.expires_at > UTC_TIMESTAMP(6))";

    private const USER_PREDICATE = "(SELECT c.user_id FROM pn_access_context c
        JOIN users u ON u.id = c.user_id AND u.status = 'active'
        WHERE c.connection_id = CONNECTION_ID() AND c.kind = 'user' AND c.expires_at > UTC_TIMESTAMP(6))";

    public function up(): void
    {
        DB::statement("CREATE TABLE pn_access_context (
            connection_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            kind VARCHAR(6) NOT NULL,
            user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            tenant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
            membership_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
            bound_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            CONSTRAINT pn_ctx_kind_chk CHECK (kind IN ('tenant','user')),
            CONSTRAINT pn_ctx_shape_chk CHECK ((kind = 'tenant') = (tenant_id IS NOT NULL AND membership_id IS NOT NULL))
        ) ENGINE=InnoDB");

        $uuid = "'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'";

        DB::unprepared("CREATE PROCEDURE pn_bind_context(IN p_user_id CHAR(36), IN p_tenant_id CHAR(36), IN p_ttl_seconds INT UNSIGNED)
        SQL SECURITY DEFINER MODIFIES SQL DATA
        BEGIN
            DECLARE v_membership CHAR(36) DEFAULT NULL;
            IF p_user_id IS NULL OR p_tenant_id IS NULL OR p_user_id NOT REGEXP {$uuid} OR p_tenant_id NOT REGEXP {$uuid} OR p_ttl_seconds NOT BETWEEN 30 AND 3600 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_CTX_FIELD';
            END IF;
            DELETE FROM pn_access_context WHERE connection_id = CONNECTION_ID();
            SELECT tm.id INTO v_membership FROM tenant_memberships tm
              JOIN users u ON u.id = tm.user_id AND u.status = 'active'
              JOIN tenants t ON t.id = tm.tenant_id AND t.status = 'active' AND t.deleted_at IS NULL AND t.type IN ('solo','firm')
             WHERE tm.user_id = p_user_id AND tm.tenant_id = p_tenant_id AND tm.status = 'active' AND tm.deleted_at IS NULL
             LIMIT 1;
            IF v_membership IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_CTX_DENIED';
            END IF;
            INSERT INTO pn_access_context VALUES (CONNECTION_ID(), 'tenant', p_user_id, p_tenant_id, v_membership, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL p_ttl_seconds SECOND);
        END");

        DB::unprepared("CREATE PROCEDURE pn_bind_user_context(IN p_user_id CHAR(36), IN p_ttl_seconds INT UNSIGNED)
        SQL SECURITY DEFINER MODIFIES SQL DATA
        BEGIN
            IF p_user_id IS NULL OR p_user_id NOT REGEXP {$uuid} OR p_ttl_seconds NOT BETWEEN 30 AND 3600 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_CTX_FIELD';
            END IF;
            DELETE FROM pn_access_context WHERE connection_id = CONNECTION_ID();
            IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_user_id AND status = 'active') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_CTX_DENIED';
            END IF;
            INSERT INTO pn_access_context VALUES (CONNECTION_ID(), 'user', p_user_id, NULL, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL p_ttl_seconds SECOND);
        END");

        DB::unprepared('CREATE PROCEDURE pn_clear_context() SQL SECURITY DEFINER MODIFIES SQL DATA
        DELETE FROM pn_access_context WHERE connection_id = CONNECTION_ID()');

        DB::unprepared("CREATE PROCEDURE pn_assert_context() SQL SECURITY DEFINER READS SQL DATA
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM pn_access_context WHERE connection_id = CONNECTION_ID() AND expires_at > UTC_TIMESTAMP(6)) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_CTX_MISSING';
            END IF;
        END");

        // T1: one filtered, writable view per firm table. MariaDB makes a view read-only when its
        // filter reads the same table, so v_tenant_memberships can't re-check the membership on
        // each statement. It relies on the check made at bind time and the context's expiry instead.
        foreach (self::TENANT_TABLES as $table) {
            $predicate = $table === 'tenant_memberships' ? self::TENANT_PREDICATE_BOUND_ONLY : self::TENANT_PREDICATE;
            DB::statement("CREATE ALGORITHM = MERGE SQL SECURITY DEFINER VIEW v_{$table} AS
                SELECT * FROM {$table} WHERE tenant_id = {$predicate} WITH CASCADED CHECK OPTION");
        }

        // T2: mixed-scope tables split by scope.
        foreach (['documents', 'document_folders'] as $table) {
            DB::statement("CREATE ALGORITHM = MERGE SQL SECURITY DEFINER VIEW v_tenant_{$table} AS
                SELECT * FROM {$table} WHERE scope = 'tenant' AND tenant_id = ".self::TENANT_PREDICATE.' WITH CASCADED CHECK OPTION');
            DB::statement("CREATE ALGORITHM = MERGE SQL SECURITY DEFINER VIEW v_personal_{$table} AS
                SELECT * FROM {$table} WHERE scope = 'personal' AND owner_user_id = ".self::USER_PREDICATE.' WITH CASCADED CHECK OPTION');
        }
        DB::statement('CREATE ALGORITHM = MERGE SQL SECURITY DEFINER VIEW v_tenant_tax_rules AS
            SELECT * FROM tax_rules WHERE tenant_id = '.self::TENANT_PREDICATE.' WITH CASCADED CHECK OPTION');
        DB::statement('CREATE SQL SECURITY DEFINER VIEW v_effective_tax_rules AS
            SELECT * FROM tax_rules WHERE tenant_id IS NULL OR tenant_id = '.self::TENANT_PREDICATE);
        DB::statement('CREATE SQL SECURITY DEFINER VIEW v_tenant_audit AS
            SELECT * FROM audit_logs WHERE tenant_id = '.self::TENANT_PREDICATE);
        DB::statement('CREATE ALGORITHM = MERGE SQL SECURITY DEFINER VIEW v_customer_consultations AS
            SELECT * FROM consultation_requests WHERE customer_user_id = '.self::USER_PREDICATE.' WITH CASCADED CHECK OPTION');

        // T3: children without tenant_id, readable through a visible parent. Read-only.
        DB::statement("CREATE SQL SECURITY DEFINER VIEW v_document_versions AS
            SELECT v.* FROM document_versions v JOIN documents d ON d.id = v.document_id
            WHERE (d.scope = 'tenant' AND d.tenant_id = ".self::TENANT_PREDICATE.")
               OR (d.scope = 'personal' AND d.owner_user_id = ".self::USER_PREDICATE.')');
        DB::statement('CREATE SQL SECURITY DEFINER VIEW v_consultation_messages AS
            SELECT m.* FROM consultation_messages m JOIN consultation_requests r ON r.id = m.consultation_request_id
            WHERE r.tenant_id = '.self::TENANT_PREDICATE.' OR r.customer_user_id = '.self::USER_PREDICATE);
    }

    public function down(): void
    {
        $views = array_merge(
            array_map(fn ($t) => "v_{$t}", self::TENANT_TABLES),
            ['v_tenant_documents', 'v_personal_documents', 'v_tenant_document_folders', 'v_personal_document_folders', 'v_tenant_tax_rules',
                'v_effective_tax_rules', 'v_tenant_audit', 'v_customer_consultations', 'v_document_versions', 'v_consultation_messages'],
        );
        foreach ($views as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
        foreach (['pn_bind_context', 'pn_bind_user_context', 'pn_clear_context', 'pn_assert_context'] as $procedure) {
            DB::unprepared("DROP PROCEDURE IF EXISTS {$procedure}");
        }
        DB::statement('DROP TABLE IF EXISTS pn_access_context');
    }
};
