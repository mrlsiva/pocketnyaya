<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MY-7: the trigger serialises competing writes on the scope row, then uses a
 * locking read, so overlap protection doesn't depend on the caller. Date ranges
 * are inclusive, so a rule ending 31 March may be followed by one starting 1 April.
 * MariaDB 10.4 uses LOCK IN SHARE MODE where the approved target uses FOR SHARE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $body = <<<'SQL'
BEGIN
    DECLARE scope_id CHAR(36);
    DECLARE overlaps INT DEFAULT 0;
    IF NEW.deleted_at IS NULL THEN
        IF NEW.tenant_id IS NULL THEN
            SELECT id INTO scope_id FROM tenants WHERE type = 'platform' LIMIT 1 FOR UPDATE;
        ELSE
            SELECT id INTO scope_id FROM tenants WHERE id = NEW.tenant_id FOR UPDATE;
        END IF;
        SELECT COUNT(*) INTO overlaps FROM tax_rules
         WHERE code = NEW.code AND deleted_at IS NULL AND id <> NEW.id
           AND tenant_id <=> NEW.tenant_id
           AND effective_from <= COALESCE(NEW.effective_to, '9999-12-31')
           AND NEW.effective_from <= COALESCE(effective_to, '9999-12-31')
         LOCK IN SHARE MODE;
        IF overlaps > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_TAX_OVERLAP: this rule overlaps another with the same code';
        END IF;
    END IF;
END
SQL;
        DB::unprepared("CREATE TRIGGER tax_rules_overlap_ins BEFORE INSERT ON tax_rules FOR EACH ROW {$body}");
        DB::unprepared("CREATE TRIGGER tax_rules_overlap_upd BEFORE UPDATE ON tax_rules FOR EACH ROW {$body}");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tax_rules_overlap_ins');
        DB::unprepared('DROP TRIGGER IF EXISTS tax_rules_overlap_upd');
    }
};
