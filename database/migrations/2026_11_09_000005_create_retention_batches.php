<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retention enforcement (owner decision 2026-11-09): a nightly dry-run report per approved
 * rule, disposal only after one person proposes and a different person approves, holds
 * re-checked at execution. Adds the tenant_closure trigger and closure disposal statuses.
 * Batch records are never deleted: they are the evidence of what was disposed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE retention_rules DROP CONSTRAINT retention_trigger_chk');
        DB::statement("ALTER TABLE retention_rules ADD CONSTRAINT retention_trigger_chk CHECK (trigger_event IN ('account_closure','matter_closure','financial_year_end','creation','settlement_execution','request_closure','tenant_closure'))");

        DB::statement('ALTER TABLE tenant_offboarding DROP CONSTRAINT offboarding_disposal_chk');
        DB::statement("ALTER TABLE tenant_offboarding ADD CONSTRAINT offboarding_disposal_chk CHECK (disposal_status IN ('not_started','blocked_no_rule','blocked_hold','partly_disposed','disposed'))");

        Schema::create('retention_batches', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('retention_rule_id');
            $table->string('data_category', 50);
            $table->string('action', 10);
            $table->string('status', 10)->default('report');
            $table->json('items');
            $table->integer('item_count');
            $table->char('items_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->dateTime('reported_at', 6);
            $table->pnUuid('proposed_by')->nullable();
            $table->dateTime('proposed_at', 6)->nullable();
            $table->pnUuid('approved_by')->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->dateTime('executed_at', 6)->nullable();
            $table->json('summary')->nullable();
            $table->datetimes(6);

            $table->index(['status', 'reported_at']);
            $table->foreign('retention_rule_id')->references('id')->on('retention_rules');
            $table->foreign('proposed_by')->references('id')->on('users');
            $table->foreign('approved_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE retention_batches ADD CONSTRAINT retention_batch_status_chk CHECK (status IN ('report','proposed','executed','superseded','cancelled'))");
        DB::statement("ALTER TABLE retention_batches ADD CONSTRAINT retention_batch_action_chk CHECK (action IN ('delete','anonymize'))");
        DB::statement('ALTER TABLE retention_batches ADD CONSTRAINT retention_batch_two_person_chk CHECK (approved_by IS NULL OR approved_by <> proposed_by)');
        DB::statement("ALTER TABLE retention_batches ADD CONSTRAINT retention_batch_executed_chk CHECK (status <> 'executed' OR (approved_by IS NOT NULL AND executed_at IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER retention_batches_no_delete BEFORE DELETE ON retention_batches FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_RETENTION_KEPT: retention batches are never deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS retention_batches_no_delete');
        Schema::dropIfExists('retention_batches');
        DB::statement('ALTER TABLE tenant_offboarding DROP CONSTRAINT offboarding_disposal_chk');
        DB::statement("ALTER TABLE tenant_offboarding ADD CONSTRAINT offboarding_disposal_chk CHECK (disposal_status IN ('not_started','blocked_no_rule','blocked_hold'))");
        DB::statement('ALTER TABLE retention_rules DROP CONSTRAINT retention_trigger_chk');
        DB::statement("ALTER TABLE retention_rules ADD CONSTRAINT retention_trigger_chk CHECK (trigger_event IN ('account_closure','matter_closure','financial_year_end','creation','settlement_execution','request_closure'))");
    }
};
