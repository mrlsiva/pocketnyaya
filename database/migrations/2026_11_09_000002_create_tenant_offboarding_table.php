<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_offboarding, the 11th tenancy table (owner decision 2026-11-09).
 * Stages: requested → suspended (on a second Super Admin's approval) → export_open → retention.
 * cancelled is possible before retention. Final disposal is switched off until retention
 * rules are approved, so nothing here deletes data. One open closure per practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_offboarding', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->string('status', 12)->default('requested');
            $table->text('reason');
            $table->pnUuid('requested_by');
            $table->dateTime('requested_at', 6);
            $table->pnUuid('approved_by')->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->string('previous_tenant_status', 10)->nullable();
            $table->dateTime('suspended_at', 6)->nullable();
            $table->dateTime('holds_checked_at', 6)->nullable();
            $table->integer('active_holds_at_check')->nullable();
            $table->pnUuid('export_recipient_user_id')->nullable();
            $table->dateTime('export_opened_at', 6)->nullable();
            $table->dateTime('export_expires_at', 6)->nullable();
            $table->integer('export_downloads')->default(0);
            $table->dateTime('last_exported_at', 6)->nullable();
            $table->text('export_waived_reason')->nullable();
            $table->pnUuid('retention_started_by')->nullable();
            $table->dateTime('retention_started_at', 6)->nullable();
            $table->string('disposal_status', 20)->default('not_started');
            $table->pnUuid('cancelled_by')->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->text('cancel_reason')->nullable();
            $table->datetimes(6);
            $table->char('open_tenant_key', 36)->charset('ascii')->collation('ascii_bin')->nullable()
                ->storedAs("IF(status IN ('requested','suspended','export_open'), tenant_id, NULL)");

            $table->unique('open_tenant_key');
            $table->index(['tenant_id', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            foreach (['requested_by', 'approved_by', 'export_recipient_user_id', 'retention_started_by', 'cancelled_by'] as $column) {
                $table->foreign($column)->references('id')->on('users');
            }
        });
        DB::statement("ALTER TABLE tenant_offboarding ADD CONSTRAINT offboarding_status_chk CHECK (status IN ('requested','suspended','export_open','retention','cancelled'))");
        DB::statement("ALTER TABLE tenant_offboarding ADD CONSTRAINT offboarding_disposal_chk CHECK (disposal_status IN ('not_started','blocked_no_rule','blocked_hold'))");
        DB::statement('ALTER TABLE tenant_offboarding ADD CONSTRAINT offboarding_two_person_chk CHECK (approved_by IS NULL OR approved_by <> requested_by)');
        DB::statement("ALTER TABLE tenant_offboarding ADD CONSTRAINT offboarding_approved_chk CHECK (status IN ('requested','cancelled') OR approved_by IS NOT NULL)");
        DB::statement("ALTER TABLE tenant_offboarding ADD CONSTRAINT offboarding_retention_chk CHECK (status <> 'retention' OR (retention_started_at IS NOT NULL AND (last_exported_at IS NOT NULL OR export_waived_reason IS NOT NULL)))");

        // Never deleted: the closure record must outlive the practice's own data.
        DB::unprepared("CREATE TRIGGER tenant_offboarding_no_delete BEFORE DELETE ON tenant_offboarding FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_OFFBOARDING_KEPT: closure records are never deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tenant_offboarding_no_delete');
        Schema::dropIfExists('tenant_offboarding');
    }
};
