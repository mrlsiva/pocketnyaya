<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project owner's decision (2026-11-06): a verification request belongs to
 * exactly one advocate or exactly one mediator, so both share one review queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_requests', function (Blueprint $table) {
            $table->pnUuid('advocate_profile_id')->nullable()->change();
            $table->pnUuid('mediator_profile_id')->nullable()->after('advocate_profile_id');
            $table->index('mediator_profile_id');
            $table->foreign('mediator_profile_id', 'vr_mediator_fk')->references('id')->on('mediator_profiles');
        });
        DB::statement('ALTER TABLE verification_requests ADD CONSTRAINT vr_one_applicant CHECK ((advocate_profile_id IS NOT NULL) + (mediator_profile_id IS NOT NULL) = 1)');

        DB::unprepared('DROP TRIGGER IF EXISTS documents_guard_ins');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER documents_guard_ins BEFORE INSERT ON documents FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(15);
    DECLARE v_applicant CHAR(36) CHARACTER SET ascii;
    IF NEW.verification_request_id IS NOT NULL THEN
        SELECT r.status, COALESCE(a.user_id, m.user_id) INTO v_status, v_applicant
          FROM verification_requests r
          LEFT JOIN advocate_profiles a ON a.id = r.advocate_profile_id
          LEFT JOIN mediator_profiles m ON m.id = r.mediator_profile_id
         WHERE r.id = NEW.verification_request_id;
        IF NEW.owner_user_id <> v_applicant THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_VERIFICATION_DOC_OWNER: only the applicant can attach documents to their request';
        END IF;
        IF v_status NOT IN ('submitted', 'info_requested') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_VERIFICATION_DOC_LOCKED: this verification request no longer accepts documents';
        END IF;
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS documents_guard_ins');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER documents_guard_ins BEFORE INSERT ON documents FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(15);
    DECLARE v_applicant CHAR(36) CHARACTER SET ascii;
    IF NEW.verification_request_id IS NOT NULL THEN
        SELECT r.status, p.user_id INTO v_status, v_applicant
          FROM verification_requests r JOIN advocate_profiles p ON p.id = r.advocate_profile_id
         WHERE r.id = NEW.verification_request_id;
        IF NEW.owner_user_id <> v_applicant THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_VERIFICATION_DOC_OWNER: only the applicant can attach documents to their request';
        END IF;
        IF v_status NOT IN ('submitted', 'info_requested') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_VERIFICATION_DOC_LOCKED: this verification request no longer accepts documents';
        END IF;
    END IF;
END
SQL);

        DB::statement('ALTER TABLE verification_requests DROP CONSTRAINT vr_one_applicant');
        Schema::table('verification_requests', function (Blueprint $table) {
            $table->dropForeign('vr_mediator_fk');
            $table->dropIndex(['mediator_profile_id']);
            $table->dropColumn('mediator_profile_id');
            $table->pnUuid('advocate_profile_id')->nullable(false)->change();
        });
    }
};
