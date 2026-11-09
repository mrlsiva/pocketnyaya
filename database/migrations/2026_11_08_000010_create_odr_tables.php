<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Online dispute resolution. Platform level, not tenant owned: access comes only
 * from odr_participants and accepted mediator assignments (D5.5). Settlements are
 * unsigned in the MVP. Nothing here claims decree status or enforceability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odr_disputes', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('dispute_number', 20)->nullable()->unique();
            $table->pnUuid('claimant_user_id');
            $table->string('category', 16);
            $table->string('title', 200);
            $table->text('description');
            $table->decimal('claim_amount', 14, 2)->nullable();
            $table->foreignId('city_id')->nullable();
            $table->foreignId('language_id')->nullable();
            $table->string('status', 30)->default('draft');
            $table->dateTime('negotiation_deadline', 6)->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->string('closure_reason', 200)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->index(['status', 'created_at']);
            $table->index('claimant_user_id');
            $table->foreign('claimant_user_id')->references('id')->on('users');
            $table->foreign('city_id')->references('id')->on('cities');
            $table->foreign('language_id')->references('id')->on('languages');
        });
        DB::statement("ALTER TABLE odr_disputes ADD CONSTRAINT odr_category_chk CHECK (category IN ('small_claims','landlord_tenant','ecommerce','service','creator_brand','other'))");
        DB::statement("ALTER TABLE odr_disputes ADD CONSTRAINT odr_status_chk CHECK (status IN ('draft','submitted','screening','screening_failed','awaiting_respondent','negotiation','mediation','settlement_drafting','settlement_pending_acceptance','settled_unsigned','closed_no_settlement','withdrawn','expired'))");
        DB::statement('ALTER TABLE odr_disputes ADD CONSTRAINT odr_amount_chk CHECK (claim_amount IS NULL OR claim_amount >= 0)');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT doc_odr_fk FOREIGN KEY (odr_dispute_id) REFERENCES odr_disputes (id)');

        Schema::create('odr_participants', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('dispute_id');
            $table->pnUuid('user_id')->nullable();
            $table->string('participant_role', 18);
            $table->string('name', 150);
            $table->string('email')->collation('utf8mb4_bin');
            $table->text('phone')->nullable();
            $table->string('organization_name', 200)->nullable();
            $table->string('status', 8)->default('invited');
            $table->char('invitation_token_hash', 64)->charset('ascii')->collation('ascii_bin')->nullable()->unique();
            $table->dateTime('invitation_expires_at', 6)->nullable();
            $table->dateTime('invited_at', 6);
            $table->dateTime('joined_at', 6)->nullable();
            $table->datetimes(6);

            $table->unique(['dispute_id', 'user_id']);
            $table->unique(['id', 'dispute_id'], 'odr_participant_dispute_uq');
            $table->foreign('dispute_id')->references('id')->on('odr_disputes');
            $table->foreign('user_id')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE odr_participants ADD CONSTRAINT odr_part_role_chk CHECK (participant_role IN ('claimant','respondent','claimant_counsel','respondent_counsel','mediator','observer'))");
        DB::statement("ALTER TABLE odr_participants ADD CONSTRAINT odr_part_status_chk CHECK (status IN ('invited','joined','declined','removed'))");

        Schema::create('odr_screenings', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('dispute_id');
            $table->json('questionnaire');
            $table->string('rules_version', 20);
            $table->string('result', 12);
            $table->json('reasons')->nullable();
            $table->pnUuid('reviewed_by')->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->foreign('dispute_id')->references('id')->on('odr_disputes');
            $table->foreign('reviewed_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE odr_screenings ADD CONSTRAINT odr_screen_result_chk CHECK (result IN ('suitable','unsuitable','needs_review'))");

        Schema::create('odr_messages', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('dispute_id');
            $table->pnUuid('sender_participant_id')->nullable();
            $table->pnUuid('sender_mediator_profile_id')->nullable();
            $table->string('message_type', 14)->default('text');
            $table->string('visibility', 16)->default('all_parties');
            $table->pnUuid('private_with_participant_id')->nullable();
            $table->text('body');
            $table->pnUuid('document_id')->nullable();
            $table->json('tone_check')->nullable();
            $table->char('content_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->dateTime('created_at', 6)->useCurrent();

            $table->index(['dispute_id', 'created_at']);
            $table->foreign('dispute_id')->references('id')->on('odr_disputes');
            $table->foreign(['sender_participant_id', 'dispute_id'], 'odr_msg_sender_fk')->references(['id', 'dispute_id'])->on('odr_participants');
            $table->foreign(['private_with_participant_id', 'dispute_id'], 'odr_msg_private_fk')->references(['id', 'dispute_id'])->on('odr_participants');
            $table->foreign('sender_mediator_profile_id')->references('id')->on('mediator_profiles');
            $table->foreign('document_id')->references('id')->on('documents');
        });
        DB::statement("ALTER TABLE odr_messages ADD CONSTRAINT odr_msg_type_chk CHECK (message_type IN ('text','offer','counter_offer','acceptance','rejection','evidence','system'))");
        DB::statement("ALTER TABLE odr_messages ADD CONSTRAINT odr_msg_vis_chk CHECK (visibility IN ('all_parties','mediator_private'))");
        DB::statement("ALTER TABLE odr_messages ADD CONSTRAINT odr_msg_caucus_chk CHECK ((visibility = 'mediator_private') = (private_with_participant_id IS NOT NULL))");

        Schema::create('odr_offers', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('dispute_id');
            $table->pnUuid('message_id');
            $table->pnUuid('offered_by_participant_id');
            $table->decimal('amount', 14, 2)->nullable();
            $table->json('terms')->nullable();
            $table->string('status', 10)->default('open');
            $table->dateTime('responded_at', 6)->nullable();
            $table->datetimes(6);
            $table->foreign('dispute_id')->references('id')->on('odr_disputes');
            $table->foreign('message_id')->references('id')->on('odr_messages');
            $table->foreign(['offered_by_participant_id', 'dispute_id'], 'odr_offer_by_fk')->references(['id', 'dispute_id'])->on('odr_participants');
        });
        DB::statement("ALTER TABLE odr_offers ADD CONSTRAINT odr_offer_status_chk CHECK (status IN ('open','accepted','rejected','countered','withdrawn','expired'))");
        DB::statement('ALTER TABLE odr_offers ADD CONSTRAINT odr_offer_amount_chk CHECK (amount IS NULL OR amount >= 0)');

        Schema::create('odr_mediator_assignments', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('dispute_id');
            $table->pnUuid('mediator_profile_id');
            $table->pnUuid('assigned_by');
            $table->string('status', 10)->default('proposed');
            $table->text('conflict_disclosure')->nullable();
            $table->dateTime('disclosed_at', 6)->nullable();
            $table->dateTime('accepted_at', 6)->nullable();
            $table->datetimes(6);
            $table->index(['mediator_profile_id', 'status']);
            $table->foreign('dispute_id')->references('id')->on('odr_disputes');
            $table->foreign('mediator_profile_id')->references('id')->on('mediator_profiles');
            $table->foreign('assigned_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE odr_mediator_assignments ADD CONSTRAINT odr_assign_status_chk CHECK (status IN ('proposed','accepted','declined','withdrawn','completed'))");

        Schema::create('odr_sessions', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('dispute_id');
            $table->dateTime('scheduled_at', 6);
            $table->integer('duration_minutes');
            $table->string('mode', 10);
            $table->string('provider', 30)->nullable();
            $table->string('meeting_ref', 200)->nullable();
            $table->string('status', 10)->default('scheduled');
            $table->text('summary')->nullable();
            $table->datetimes(6);
            $table->foreign('dispute_id')->references('id')->on('odr_disputes');
        });
        DB::statement("ALTER TABLE odr_sessions ADD CONSTRAINT odr_session_mode_chk CHECK (mode IN ('video','audio','in_person'))");
        DB::statement("ALTER TABLE odr_sessions ADD CONSTRAINT odr_session_status_chk CHECK (status IN ('scheduled','held','cancelled','no_show'))");
        DB::statement('ALTER TABLE odr_sessions ADD CONSTRAINT odr_session_duration_chk CHECK (duration_minutes > 0)');

        Schema::create('settlement_templates', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('category', 16);
            $table->integer('version');
            $table->string('title', 200);
            $table->text('body_template');
            $table->string('legal_review_status', 8)->default('draft');
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->datetimes(6);
            $table->unique(['category', 'version']);
        });
        DB::statement("ALTER TABLE settlement_templates ADD CONSTRAINT template_review_chk CHECK (legal_review_status IN ('draft','reviewed','retired'))");

        Schema::create('odr_settlements', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('dispute_id');
            $table->integer('version_number');
            $table->pnUuid('template_id');
            $table->json('terms');
            $table->pnUuid('document_id')->nullable();
            $table->char('content_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('status', 15)->default('under_review');
            $table->pnUuid('generated_by');
            $table->dateTime('generated_at', 6);
            $table->datetimes(6);
            $table->unique(['dispute_id', 'version_number']);
            $table->foreign('dispute_id')->references('id')->on('odr_disputes');
            $table->foreign('template_id')->references('id')->on('settlement_templates');
            $table->foreign('document_id')->references('id')->on('documents');
            $table->foreign('generated_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE odr_settlements ADD CONSTRAINT settlement_status_chk CHECK (status IN ('draft','under_review','accepted_by_all','void','signed'))");

        Schema::create('settlement_acceptances', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('settlement_id');
            $table->pnUuid('participant_id');
            $table->string('method', 22)->default('electronic_acceptance');
            $table->string('status', 8)->default('pending');
            $table->string('ip', 45)->nullable();
            $table->string('provider_txn_id', 100)->nullable();
            $table->json('certificate_data')->nullable();
            $table->dateTime('acted_at', 6)->nullable();
            $table->datetimes(6);
            $table->unique(['settlement_id', 'participant_id']);
            $table->foreign('settlement_id')->references('id')->on('odr_settlements');
            $table->foreign('participant_id')->references('id')->on('odr_participants');
        });
        DB::statement("ALTER TABLE settlement_acceptances ADD CONSTRAINT acceptance_method_chk CHECK (method IN ('electronic_acceptance','aadhaar_esign','dsc'))");
        DB::statement("ALTER TABLE settlement_acceptances ADD CONSTRAINT acceptance_status_chk CHECK (status IN ('pending','accepted','declined','signed','failed'))");

        foreach (['odr_screenings', 'odr_messages'] as $name) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared("CREATE TRIGGER {$name}_ao_{$event} BEFORE {$event} ON {$name} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_APPEND_ONLY: {$name}'");
            }
        }
    }

    public function down(): void
    {
        foreach (['settlement_acceptances', 'odr_settlements', 'settlement_templates', 'odr_sessions', 'odr_mediator_assignments', 'odr_offers', 'odr_messages', 'odr_screenings', 'odr_participants'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE documents DROP FOREIGN KEY doc_odr_fk');
        Schema::dropIfExists('odr_disputes');
    }
};
