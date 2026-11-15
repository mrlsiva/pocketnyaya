<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C27: foreign keys stop a personal folder pointing at a tenant folder or at
 * another user's folder, and stop a tenant folder crossing into another tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('scope', 10)->charset('ascii')->collation('ascii_bin');
            $table->pnUuid('tenant_id')->nullable();
            $table->pnUuid('owner_user_id');
            $table->pnUuid('parent_id')->nullable();
            $table->string('name', 150);
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);
            // Set by trigger: MariaDB 10.11 refuses this as a stored generated column (error 1901).
            $table->char('personal_owner_id', 36)->charset('ascii')->collation('ascii_bin')->nullable();

            $table->unique(['id', 'scope'], 'df_id_scope_uq');
            $table->unique(['tenant_id', 'id'], 'df_tenant_id_uq');
            $table->unique(['personal_owner_id', 'id'], 'df_owner_id_uq');
            $table->foreign('tenant_id', 'df_tenant_fk')->references('id')->on('tenants');
            $table->foreign('owner_user_id', 'df_owner_user_fk')->references('id')->on('users');
            $table->foreign(['parent_id', 'scope'], 'df_parent_scope_fk')
                ->references(['id', 'scope'])->on('document_folders');
            $table->foreign(['tenant_id', 'parent_id'], 'df_parent_tenant_fk')
                ->references(['tenant_id', 'id'])->on('document_folders');
            $table->foreign(['personal_owner_id', 'parent_id'], 'df_parent_owner_fk')
                ->references(['personal_owner_id', 'id'])->on('document_folders');
        });
        foreach (['INSERT' => 'bi', 'UPDATE' => 'bu'] as $event => $suffix) {
            DB::unprepared("CREATE TRIGGER document_folders_keys_{$suffix} BEFORE {$event} ON document_folders FOR EACH ROW
                SET NEW.personal_owner_id = IF(NEW.scope = 'personal', NEW.owner_user_id, NULL)");
        }
        DB::statement("ALTER TABLE document_folders ADD CONSTRAINT df_scope_values CHECK (scope IN ('tenant','personal'))");
        DB::statement("ALTER TABLE document_folders ADD CONSTRAINT df_scope_tenant CHECK ((scope = 'tenant') = (tenant_id IS NOT NULL))");
        DB::statement('ALTER TABLE document_folders ADD CONSTRAINT df_not_self_parent CHECK (parent_id IS NULL OR parent_id <> id)');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_folders_guard_upd BEFORE UPDATE ON document_folders FOR EACH ROW
BEGIN
    DECLARE v_cycle INT DEFAULT 0;
    IF NEW.scope <> OLD.scope OR NOT (NEW.tenant_id <=> OLD.tenant_id) OR NEW.owner_user_id <> OLD.owner_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_DOC_IMMUTABLE: folder scope, tenant and owner cannot change';
    END IF;
    IF NEW.parent_id IS NOT NULL AND NOT (NEW.parent_id <=> OLD.parent_id) THEN
        SELECT COUNT(*) INTO v_cycle FROM (
            WITH RECURSIVE ancestors (id, parent_id) AS (
                SELECT id, parent_id FROM document_folders WHERE id = NEW.parent_id
                UNION ALL
                SELECT f.id, f.parent_id FROM document_folders f JOIN ancestors a ON f.id = a.parent_id
            )
            SELECT id FROM ancestors WHERE id = NEW.id
        ) AS found;
        IF v_cycle > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_FOLDER_CYCLE: folder cannot be moved inside itself';
        END IF;
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS document_folders_guard_upd');
        Schema::dropIfExists('document_folders');
    }
};
