<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C7: versions are append-only and keys are mutable, so a master key can be
 * rotated without touching version rows. C31: destroying a wrapped data key
 * leaves a tombstone, and the filename and MAC become unreadable with it.
 */
return new class extends Migration
{
    private const TRIGGERS = [
        'document_versions_guard_ins', 'document_versions_guard_upd', 'document_versions_guard_del',
        'document_keys_guard_ins', 'document_keys_guard_upd', 'document_keys_guard_del',
    ];

    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('document_id');
            $table->integer('version_number');
            $table->string('storage_disk', 30);
            $table->string('storage_region', 20);
            $table->string('storage_path', 500);
            // Encrypted with this version's own data key (C31).
            $table->text('original_filename_enc');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            // HMAC-SHA256 under a key derived from the data key (C31).
            $table->char('plaintext_mac', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('cipher', 15)->default('aes-256-gcm');
            $table->integer('chunk_size');
            $table->binary('nonce_prefix', 8);
            $table->string('scan_status', 10)->default('pending');
            $table->pnUuid('uploaded_by');
            $table->text('change_note')->nullable();
            $table->dateTime('created_at', 6)->useCurrent();

            $table->unique(['document_id', 'version_number']);
            $table->foreign('document_id')->references('id')->on('documents');
            $table->foreign('uploaded_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT dv_cipher_chk CHECK (cipher = 'aes-256-gcm')");
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT dv_scan_chk CHECK (scan_status IN ('pending','clean','infected','skipped'))");
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT dv_private_disk_chk CHECK (storage_disk <> 'public')");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_versions_guard_ins BEFORE INSERT ON document_versions FOR EACH ROW
BEGIN
    DECLARE v_request CHAR(36) CHARACTER SET ascii;
    DECLARE v_owner CHAR(36) CHARACTER SET ascii;
    DECLARE v_status VARCHAR(15);
    SELECT verification_request_id, owner_user_id INTO v_request, v_owner FROM documents WHERE id = NEW.document_id;
    IF v_request IS NOT NULL THEN
        IF NEW.uploaded_by <> v_owner THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_VERIFICATION_DOC_OWNER: only the applicant can replace a verification document';
        END IF;
        SELECT status INTO v_status FROM verification_requests WHERE id = v_request;
        IF v_status NOT IN ('submitted', 'info_requested') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_VERIFICATION_DOC_LOCKED: this verification request no longer accepts documents';
        END IF;
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_versions_guard_upd BEFORE UPDATE ON document_versions FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_APPEND_ONLY: document versions cannot be changed'
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_versions_guard_del BEFORE DELETE ON document_versions FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_APPEND_ONLY: document versions cannot be deleted'
SQL);

        Schema::create('document_keys', function (Blueprint $table) {
            $table->pnUuid('document_version_id')->primary();
            $table->smallInteger('kek_version_id')->index();
            $table->text('wrapped_dek')->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('rewrapped_at', 6)->nullable();
            $table->dateTime('destroyed_at', 6)->nullable();
            $table->string('destroyed_reason', 30)->nullable();

            $table->foreign('document_version_id', 'dk_version_fk')->references('id')->on('document_versions');
            $table->foreign('kek_version_id', 'dk_kek_fk')->references('id')->on('encryption_key_versions');
        });
        DB::statement('ALTER TABLE document_keys ADD CONSTRAINT dk_tombstone CHECK ((wrapped_dek IS NULL) = (destroyed_at IS NOT NULL))');
        DB::statement('ALTER TABLE document_keys ADD CONSTRAINT dk_reason CHECK ((destroyed_at IS NULL) = (destroyed_reason IS NULL))');
        DB::statement("ALTER TABLE document_keys ADD CONSTRAINT dk_reason_values CHECK (destroyed_reason IN ('erasure','retention_expiry','tenant_offboarding','compromise'))");

        $wrapCheck = <<<'SQL'
    IF NOT EXISTS (SELECT 1 FROM encryption_key_versions WHERE id = NEW.kek_version_id AND purpose = 'documents' AND status = 'active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_KEY_WRAP: data keys must be wrapped with the active documents key';
    END IF;
SQL;

        DB::unprepared(<<<SQL
CREATE TRIGGER document_keys_guard_ins BEFORE INSERT ON document_keys FOR EACH ROW
BEGIN
{$wrapCheck}
END
SQL);
        DB::unprepared(<<<SQL
CREATE TRIGGER document_keys_guard_upd BEFORE UPDATE ON document_keys FOR EACH ROW
BEGIN
    IF OLD.destroyed_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_KEY_DESTROYED: a destroyed key cannot change';
    END IF;
    IF NEW.document_version_id <> OLD.document_version_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_KEY_VERSION_LINK: a key cannot move to another document version';
    END IF;
    IF NEW.kek_version_id <> OLD.kek_version_id THEN
{$wrapCheck}
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_keys_guard_del BEFORE DELETE ON document_keys FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_KEY_DELETE: key rows are tombstoned, never deleted'
SQL);
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('document_keys');
        Schema::dropIfExists('document_versions');
    }
};
