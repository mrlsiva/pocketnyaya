<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy, compliance and audit. Evidence is encrypted under a per-user key (C44),
 * consent records form a hash chain (MY-10), and the append-only tables refuse
 * changes at database level. audit_logs isn't partitioned (MY-18 option B).
 */
return new class extends Migration
{
    private const APPEND_ONLY = ['disclaimer_acceptances', 'consent_records', 'erasure_actions', 'audit_logs'];

    public function up(): void
    {
        Schema::create('disclaimers', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('code', 50);
            $table->integer('version');
            $table->string('title', 200);
            $table->text('body');
            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->datetimes(6);
            $table->unique(['code', 'version']);
        });

        Schema::create('disclaimer_acceptances', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('disclaimer_id');
            $table->pnUuid('user_id')->nullable();
            $table->string('anonymous_session_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->foreign('disclaimer_id')->references('id')->on('disclaimers');
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::create('retention_rules', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('data_category', 50);
            $table->integer('version');
            $table->integer('retention_months')->nullable();
            $table->string('trigger_event', 22);
            $table->string('on_expiry', 10);
            $table->string('on_erasure_request', 10);
            $table->text('legal_basis_note');
            $table->string('status', 8)->default('draft');
            $table->pnUuid('drafted_by');
            $table->pnUuid('approved_by')->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->text('approval_evidence')->nullable();
            $table->datetimes(6);
            $table->unique(['data_category', 'version']);
            $table->foreign('drafted_by')->references('id')->on('users');
            $table->foreign('approved_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE retention_rules ADD CONSTRAINT retention_trigger_chk CHECK (trigger_event IN ('account_closure','matter_closure','financial_year_end','creation','settlement_execution','request_closure'))");
        DB::statement("ALTER TABLE retention_rules ADD CONSTRAINT retention_expiry_chk CHECK (on_expiry IN ('delete','anonymize','archive','review'))");
        DB::statement("ALTER TABLE retention_rules ADD CONSTRAINT retention_erasure_chk CHECK (on_erasure_request IN ('erase','anonymize','retain'))");
        DB::statement("ALTER TABLE retention_rules ADD CONSTRAINT retention_status_chk CHECK (status IN ('draft','approved','retired'))");
        DB::statement('ALTER TABLE retention_rules ADD CONSTRAINT retention_recorder_chk CHECK (approved_by IS NULL OR approved_by <> drafted_by)');

        Schema::create('consent_purposes', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('code', 50);
            $table->integer('version');
            $table->string('name', 150);
            $table->text('description');
            $table->boolean('is_mandatory')->default(false);
            $table->pnUuid('retention_rule_id')->nullable();
            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->datetimes(6);
            $table->unique(['code', 'version']);
            $table->foreign('retention_rule_id')->references('id')->on('retention_rules');
        });

        Schema::create('consent_records', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('user_id');
            $table->unsignedBigInteger('sequence_no');
            $table->pnUuid('consent_purpose_id');
            $table->string('action', 9);
            $table->string('channel', 6);
            $table->pnUuid('consent_key_id');
            $table->text('evidence_encrypted');
            $table->char('evidence_commitment', 64)->charset('ascii')->collation('ascii_bin');
            $table->char('prev_hash', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->char('record_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->dateTime('created_at', 6);

            $table->unique(['user_id', 'sequence_no'], 'cr_seq_uq');
            $table->unique('record_hash', 'cr_hash_uq');
            $table->unique(['user_id', 'prev_hash'], 'cr_no_fork');
            $table->index(['user_id', 'consent_purpose_id', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('consent_purpose_id')->references('id')->on('consent_purposes');
        });
        DB::statement("ALTER TABLE consent_records ADD CONSTRAINT cr_action_chk CHECK (action IN ('granted','withdrawn','renewed'))");
        DB::statement("ALTER TABLE consent_records ADD CONSTRAINT cr_channel_chk CHECK (channel IN ('web','api','mobile'))");
        DB::statement('ALTER TABLE consent_records ADD CONSTRAINT cr_seq_positive CHECK (sequence_no >= 1)');
        DB::statement('ALTER TABLE consent_records ADD CONSTRAINT cr_genesis CHECK ((sequence_no = 1) = (prev_hash IS NULL))');

        Schema::create('user_consents', function (Blueprint $table) {
            $table->pnUuid('user_id');
            $table->pnUuid('consent_purpose_id');
            $table->string('status', 9);
            $table->dateTime('updated_at', 6);
            $table->primary(['user_id', 'consent_purpose_id']);
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('consent_purpose_id')->references('id')->on('consent_purposes');
        });
        DB::statement("ALTER TABLE user_consents ADD CONSTRAINT uc_status_chk CHECK (status IN ('granted','withdrawn'))");

        Schema::create('legal_holds', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('holdable_type', 30);
            $table->string('holdable_id', 64);
            $table->pnUuid('tenant_id')->nullable();
            $table->text('reason');
            $table->pnUuid('placed_by');
            $table->dateTime('placed_at', 6);
            $table->dateTime('released_at', 6)->nullable();
            $table->pnUuid('released_by')->nullable();
            $table->datetimes(6);
            $table->index(['holdable_type', 'holdable_id', 'released_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('placed_by')->references('id')->on('users');
            $table->foreign('released_by')->references('id')->on('users');
        });
        DB::unprepared("CREATE TRIGGER legal_holds_guard BEFORE UPDATE ON legal_holds FOR EACH ROW
            IF OLD.released_at IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_HOLD_RELEASED: a released hold cannot change'; END IF");

        Schema::create('data_principal_requests', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('user_id');
            $table->string('request_type', 10);
            $table->text('details')->nullable();
            $table->string('status', 22)->default('submitted');
            $table->dateTime('identity_verified_at', 6)->nullable();
            $table->pnUuid('verified_by')->nullable();
            $table->pnUuid('reviewer_id')->nullable();
            $table->text('decision_reason')->nullable();
            $table->json('retained_items')->nullable();
            $table->dateTime('due_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->datetimes(6);
            $table->index(['status', 'due_at']);
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('verified_by')->references('id')->on('users');
            $table->foreign('reviewer_id')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE data_principal_requests ADD CONSTRAINT dpr_type_chk CHECK (request_type IN ('erasure','access','correction','grievance','nomination'))");
        DB::statement("ALTER TABLE data_principal_requests ADD CONSTRAINT dpr_status_chk CHECK (status IN ('submitted','identity_verification','in_review','approved','partially_fulfilled','completed','rejected','withdrawn'))");

        Schema::create('erasure_actions', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('request_id');
            $table->string('target_table', 64);
            $table->string('target_id', 64);
            $table->string('action', 12);
            $table->pnUuid('retention_rule_id')->nullable();
            $table->json('details')->nullable();
            $table->dateTime('executed_at', 6)->useCurrent();
            $table->foreign('request_id')->references('id')->on('data_principal_requests');
            $table->foreign('retention_rule_id')->references('id')->on('retention_rules');
        });
        DB::statement("ALTER TABLE erasure_actions ADD CONSTRAINT erasure_action_chk CHECK (action IN ('anonymized','hard_deleted','retained','shredded'))");

        Schema::create('data_breach_incidents', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->dateTime('detected_at', 6);
            $table->text('summary');
            $table->string('severity', 8);
            $table->integer('affected_principals')->nullable();
            $table->dateTime('board_notified_at', 6)->nullable();
            $table->dateTime('principals_notified_at', 6)->nullable();
            $table->string('status', 9)->default('open');
            $table->pnUuid('owner_id');
            $table->datetimes(6);
            $table->foreign('owner_id')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE data_breach_incidents ADD CONSTRAINT breach_severity_chk CHECK (severity IN ('low','medium','high','critical'))");
        DB::statement("ALTER TABLE data_breach_incidents ADD CONSTRAINT breach_status_chk CHECK (status IN ('open','contained','reported','closed'))");

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->pnUuid('tenant_id')->nullable();
            $table->pnUuid('actor_user_id')->nullable();
            $table->string('actor_type', 6);
            $table->string('event', 60);
            $table->string('auditable_type', 40)->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('request_id', 36)->nullable();
            $table->string('channel', 8);
            $table->char('row_hash', 64)->charset('ascii')->collation('ascii_bin')->default('');
            $table->dateTime('created_at', 6)->default(DB::raw('CURRENT_TIMESTAMP(6)'));
            $table->index(['tenant_id', 'created_at', 'id'], 'al_tenant_keyset');
            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'al_auditable');
        });
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_actor_chk CHECK (actor_type IN ('user','system','api'))");
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_channel_chk CHECK (channel IN ('web','api','queue','console'))");
        DB::unprepared("CREATE TRIGGER audit_logs_hash BEFORE INSERT ON audit_logs FOR EACH ROW
            SET NEW.row_hash = SHA2(CONCAT_WS('|', COALESCE(NEW.tenant_id,''), COALESCE(NEW.actor_user_id,''), NEW.actor_type, NEW.event,
                COALESCE(NEW.auditable_type,''), COALESCE(NEW.auditable_id,''), COALESCE(NEW.old_values,''), COALESCE(NEW.new_values,''),
                COALESCE(NEW.ip,''), NEW.channel, DATE_FORMAT(NEW.created_at, '%Y-%m-%d %H:%i:%s.%f')), 256)");

        Schema::create('audit_digests', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id')->nullable();
            $table->date('digest_date');
            $table->unsignedBigInteger('first_id');
            $table->unsignedBigInteger('last_id');
            $table->char('root_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->string('tenant_key', 36)->charset('ascii')->collation('ascii_bin')->nullable(); // set by trigger (MariaDB 10.11)
            $table->unique(['tenant_key', 'digest_date'], 'audit_digest_uq');
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        // Append-only below blocks updates, so only inserts need the key.
        DB::unprepared("CREATE TRIGGER audit_digests_keys_bi BEFORE INSERT ON audit_digests FOR EACH ROW SET NEW.tenant_key = COALESCE(NEW.tenant_id, 'PLATFORM')");

        foreach (self::APPEND_ONLY as $name) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared("CREATE TRIGGER {$name}_ao_{$event} BEFORE {$event} ON {$name} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_APPEND_ONLY: {$name}'");
            }
        }
    }

    public function down(): void
    {
        foreach (['audit_digests', 'audit_logs', 'data_breach_incidents', 'erasure_actions', 'data_principal_requests', 'legal_holds', 'user_consents', 'consent_records', 'consent_purposes', 'retention_rules', 'disclaimer_acceptances', 'disclaimers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
