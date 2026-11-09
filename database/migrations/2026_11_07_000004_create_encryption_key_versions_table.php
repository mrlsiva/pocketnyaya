<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata only. Key material is never stored in the database (C7, C31, C44).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encryption_key_versions', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->string('purpose', 10);
            $table->string('provider', 5);
            $table->string('key_ref', 255);
            // Key check value: not secret, confirms a recovered key is the right one.
            $table->char('kcv', 16)->charset('ascii')->collation('ascii_bin');
            $table->string('status', 12);
            $table->dateTime('activated_at', 6);
            $table->dateTime('retired_at', 6)->nullable();
            $table->datetimes(6);
            // Replaces the PostgreSQL partial unique index: one active key per purpose.
            $table->string('active_purpose', 10)->storedAs("IF(status = 'active', purpose, NULL)");

            $table->unique('active_purpose');
        });
        DB::statement("ALTER TABLE encryption_key_versions ADD CONSTRAINT ekv_purpose_chk CHECK (purpose IN ('documents','journal'))");
        DB::statement("ALTER TABLE encryption_key_versions ADD CONSTRAINT ekv_provider_chk CHECK (provider IN ('local','kms'))");
        DB::statement("ALTER TABLE encryption_key_versions ADD CONSTRAINT ekv_status_chk CHECK (status IN ('active','decrypt_only','disabled','destroyed'))");

        // Statuses only move forward, and a key version's identity never changes.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER ekv_guard_upd BEFORE UPDATE ON encryption_key_versions FOR EACH ROW
BEGIN
    IF NEW.id <> OLD.id OR NEW.purpose <> OLD.purpose OR NEW.provider <> OLD.provider
       OR NEW.key_ref <> OLD.key_ref OR NEW.kcv <> OLD.kcv THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_KEY_VERSION_IMMUTABLE: key version identity cannot change';
    END IF;
    IF FIELD(NEW.status, 'active', 'decrypt_only', 'disabled', 'destroyed')
       < FIELD(OLD.status, 'active', 'decrypt_only', 'disabled', 'destroyed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_KEY_VERSION_STATUS: key version status can only move forward';
    END IF;
END
SQL);

        // C44: the column has existed since Module 1, waiting for this table.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('consent_key_kek_version', 'users_consent_kek_fk')
                ->references('id')->on('encryption_key_versions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_consent_kek_fk');
            $table->dropIndex('users_consent_kek_fk');
        });
        DB::unprepared('DROP TRIGGER IF EXISTS ekv_guard_upd');
        Schema::dropIfExists('encryption_key_versions');
    }
};
