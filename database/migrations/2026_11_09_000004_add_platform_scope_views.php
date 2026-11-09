<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Platform-scope rows of mixed tables (C52 T2): ODR evidence, verification files and platform
 * tax rules. Access to these stays application-controlled (residual risk T4), but pn_app reads
 * them through these views instead of the base tables, so it never gets firm or personal rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['documents', 'document_folders'] as $table) {
            DB::statement("CREATE ALGORITHM = MERGE SQL SECURITY DEFINER VIEW v_platform_{$table} AS
                SELECT * FROM {$table} WHERE scope = 'platform' WITH CASCADED CHECK OPTION");
        }
        DB::statement('CREATE ALGORITHM = MERGE SQL SECURITY DEFINER VIEW v_platform_tax_rules AS
            SELECT * FROM tax_rules WHERE tenant_id IS NULL WITH CASCADED CHECK OPTION');
    }

    public function down(): void
    {
        foreach (['v_platform_documents', 'v_platform_document_folders', 'v_platform_tax_rules'] as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
    }
};
