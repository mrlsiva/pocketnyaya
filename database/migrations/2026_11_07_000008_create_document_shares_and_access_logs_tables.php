<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TRIGGERS = ['document_shares_guard_ins', 'document_access_logs_guard_upd', 'document_access_logs_guard_del'];

    public function up(): void
    {
        // Named users and tenants only. There are no anonymous links and no
        // edit level, following Step 3 (DA-22, DA-28) and D8.8.
        Schema::create('document_shares', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('document_id')->index();
            $table->pnUuid('shared_by');
            $table->string('grantee_type', 10)->charset('ascii')->collation('ascii_bin');
            $table->pnUuid('grantee_user_id')->nullable()->index();
            $table->pnUuid('grantee_tenant_id')->nullable()->index();
            $table->string('permission', 10);
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->datetimes(6);

            $table->foreign('document_id')->references('id')->on('documents');
            $table->foreign('shared_by')->references('id')->on('users');
            $table->foreign('grantee_user_id')->references('id')->on('users');
            $table->foreign('grantee_tenant_id')->references('id')->on('tenants');
        });
        DB::statement("ALTER TABLE document_shares ADD CONSTRAINT ds_grantee_chk CHECK ((grantee_type = 'user' AND grantee_user_id IS NOT NULL AND grantee_tenant_id IS NULL) OR (grantee_type = 'tenant' AND grantee_tenant_id IS NOT NULL AND grantee_user_id IS NULL))");
        DB::statement("ALTER TABLE document_shares ADD CONSTRAINT ds_permission_chk CHECK (permission IN ('view','download'))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_shares_guard_ins BEFORE INSERT ON document_shares FOR EACH ROW
BEGIN
    IF (SELECT scope FROM documents WHERE id = NEW.document_id) = 'platform' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_SHARE_PLATFORM_DOC: platform documents cannot be shared';
    END IF;
END
SQL);

        // Append-only and unpartitioned (C60). No foreign keys, so logs outlive their subjects.
        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->id();
            $table->pnUuid('document_id');
            $table->pnUuid('version_id')->nullable();
            $table->pnUuid('user_id')->nullable();
            $table->pnUuid('tenant_id')->nullable();
            $table->string('action', 10);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 36)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();

            $table->index(['document_id', 'created_at'], 'dal_document_idx');
            $table->index(['tenant_id', 'created_at'], 'dal_tenant_idx');
        });
        DB::statement("ALTER TABLE document_access_logs ADD CONSTRAINT dal_action_chk CHECK (action IN ('view','download','upload','share','revoke','delete','restore','purge'))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_access_logs_guard_upd BEFORE UPDATE ON document_access_logs FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_APPEND_ONLY: access logs cannot be changed'
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_access_logs_guard_del BEFORE DELETE ON document_access_logs FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_APPEND_ONLY: access logs cannot be deleted'
SQL);
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('document_access_logs');
        Schema::dropIfExists('document_shares');
    }
};
