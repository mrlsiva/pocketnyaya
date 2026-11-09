<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C5: exclusive arc of typed links instead of a polymorphic relation.
 * C27: folder links are checked for scope, tenant and personal owner.
 *
 * matter_id, client_id and odr_dispute_id have no foreign key yet. Each key is
 * added by the module that creates its target table.
 */
return new class extends Migration
{
    private const TRIGGERS = ['documents_guard_ins', 'documents_guard_upd', 'documents_guard_del'];

    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('scope', 10)->charset('ascii')->collation('ascii_bin');
            $table->pnUuid('tenant_id')->nullable();
            $table->pnUuid('owner_user_id');
            $table->pnUuid('folder_id')->nullable();
            $table->pnUuid('matter_id')->nullable();
            $table->pnUuid('client_id')->nullable();
            $table->pnUuid('odr_dispute_id')->nullable();
            $table->pnUuid('verification_request_id')->nullable();
            $table->string('title', 200);
            $table->string('category', 15);
            $table->string('confidentiality', 12)->default('normal');
            $table->string('source', 10)->default('upload');
            $table->string('status', 10)->default('active');
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);
            $table->char('personal_owner_id', 36)->charset('ascii')->collation('ascii_bin')
                ->storedAs("IF(scope = 'personal', owner_user_id, NULL)");

            $table->unique(['tenant_id', 'id'], 'doc_tenant_id_uq');
            $table->index(['owner_user_id', 'scope'], 'doc_owner_scope_idx');
            $table->index('matter_id');
            $table->index('client_id');
            $table->index('odr_dispute_id');
            $table->foreign('tenant_id', 'doc_tenant_fk')->references('id')->on('tenants');
            $table->foreign('owner_user_id', 'doc_owner_user_fk')->references('id')->on('users');
            $table->foreign('verification_request_id', 'doc_verification_fk')->references('id')->on('verification_requests');
            $table->foreign(['folder_id', 'scope'], 'doc_folder_scope_fk')
                ->references(['id', 'scope'])->on('document_folders');
            $table->foreign(['tenant_id', 'folder_id'], 'doc_folder_tenant_fk')
                ->references(['tenant_id', 'id'])->on('document_folders');
            $table->foreign(['personal_owner_id', 'folder_id'], 'doc_folder_owner_fk')
                ->references(['personal_owner_id', 'id'])->on('document_folders');
        });

        $checks = [
            'doc_scope_values' => "scope IN ('tenant','personal','platform')",
            'doc_scope_tenant' => "(scope = 'tenant') = (tenant_id IS NOT NULL)",
            'doc_arc_single' => '((matter_id IS NOT NULL) + (client_id IS NOT NULL) + (odr_dispute_id IS NOT NULL) + (verification_request_id IS NOT NULL)) <= 1',
            'doc_arc_tenant' => "(matter_id IS NULL AND client_id IS NULL) OR scope = 'tenant'",
            'doc_arc_platform' => "(odr_dispute_id IS NULL AND verification_request_id IS NULL) OR scope = 'platform'",
            'doc_platform_no_folder' => "scope <> 'platform' OR folder_id IS NULL",
            'doc_category_values' => "category IN ('pleading','order','evidence','identity','property','notice','contract','invoice','settlement','verification','other')",
            'doc_confidentiality_values' => "confidentiality IN ('normal','confidential','privileged')",
            'doc_source_values' => "source IN ('upload','generated','ai_draft')",
            'doc_status_values' => "status IN ('active','archived','purged')",
        ];
        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE documents ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER documents_guard_upd BEFORE UPDATE ON documents FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(15);
    IF NEW.scope <> OLD.scope OR NOT (NEW.tenant_id <=> OLD.tenant_id) OR NEW.owner_user_id <> OLD.owner_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_DOC_IMMUTABLE: document scope, tenant and owner cannot change';
    END IF;
    IF NOT (NEW.verification_request_id <=> OLD.verification_request_id) OR NOT (NEW.odr_dispute_id <=> OLD.odr_dispute_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_PLATFORM_DOC_LINK: a platform document link cannot change';
    END IF;
    IF OLD.scope = 'platform' AND NEW.deleted_at IS NOT NULL AND OLD.deleted_at IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_PLATFORM_DOC_DELETE: platform documents are never deleted';
    END IF;
    IF OLD.verification_request_id IS NOT NULL
       AND (NEW.title <> OLD.title OR NEW.category <> OLD.category
            OR NEW.confidentiality <> OLD.confidentiality OR NEW.source <> OLD.source) THEN
        SELECT status INTO v_status FROM verification_requests WHERE id = OLD.verification_request_id;
        IF v_status NOT IN ('submitted', 'info_requested') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_VERIFICATION_DOC_LOCKED: this verification request no longer accepts changes';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER documents_guard_del BEFORE DELETE ON documents FOR EACH ROW
BEGIN
    IF OLD.scope = 'platform' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_PLATFORM_DOC_DELETE: platform documents are never deleted';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('documents');
    }
};
