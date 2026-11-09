<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hearings', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('matter_id');
            $table->foreignId('court_id')->constrained('courts');
            $table->string('court_hall', 30)->nullable();
            $table->string('judge_name', 150)->nullable();
            $table->date('hearing_date');
            $table->time('scheduled_time')->nullable();
            $table->string('item_number', 20)->nullable();
            $table->string('purpose', 120)->nullable();
            $table->string('source', 10)->default('manual');
            $table->string('status', 12)->default('scheduled');
            $table->text('outcome_summary')->nullable();
            $table->date('next_hearing_date')->nullable();
            $table->pnUuid('next_hearing_id')->nullable();
            $table->pnUuid('order_document_id')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'hearing_date']);
            $table->index(['matter_id', 'hearing_date']);
            $table->foreign(['tenant_id', 'matter_id'], 'hearings_matter_fk')->references(['tenant_id', 'id'])->on('matters');
            $table->foreign(['tenant_id', 'next_hearing_id'], 'hearings_next_fk')->references(['tenant_id', 'id'])->on('hearings');
            $table->foreign(['tenant_id', 'order_document_id'], 'hearings_order_fk')->references(['tenant_id', 'id'])->on('documents');
        });
        DB::statement("ALTER TABLE hearings ADD CONSTRAINT hearings_source_chk CHECK (source IN ('manual','cnr_lookup','court_sync'))");
        DB::statement("ALTER TABLE hearings ADD CONSTRAINT hearings_status_chk CHECK (status IN ('scheduled','attended','adjourned','not_reached','disposed','cancelled'))");

        Schema::create('hearing_attendees', function (Blueprint $table) {
            $table->pnUuid('hearing_id');
            $table->pnUuid('membership_id')->index();
            $table->pnUuid('tenant_id');
            $table->datetimes(6);

            $table->primary(['hearing_id', 'membership_id']);
            $table->foreign(['tenant_id', 'hearing_id'], 'attendees_hearing_fk')->references(['tenant_id', 'id'])->on('hearings')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'membership_id'], 'attendees_membership_fk')->references(['tenant_id', 'id'])->on('tenant_memberships');
        });

        Schema::create('diary_entries', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('membership_id');
            $table->pnUuid('matter_id')->nullable();
            $table->string('entry_type', 16);
            $table->string('title', 200);
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6)->nullable();
            $table->string('location', 200)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->index(['tenant_id', 'membership_id', 'starts_at'], 'diary_owner_idx');
            $table->foreign(['tenant_id', 'membership_id'], 'diary_membership_fk')->references(['tenant_id', 'id'])->on('tenant_memberships');
            $table->foreign(['tenant_id', 'matter_id'], 'diary_matter_fk')->references(['tenant_id', 'id'])->on('matters');
        });
        DB::statement("ALTER TABLE diary_entries ADD CONSTRAINT diary_type_chk CHECK (entry_type IN ('meeting','reminder','filing_deadline','personal'))");

        Schema::create('schedule_conflicts', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('membership_id');
            $table->pnUuid('hearing_a_id');
            $table->pnUuid('hearing_b_id');
            $table->string('conflict_type', 26);
            $table->string('status', 12)->default('open');
            $table->pnUuid('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();
            $table->dateTime('detected_at', 6);
            $table->datetimes(6);

            $table->unique(['hearing_a_id', 'hearing_b_id', 'membership_id'], 'conflicts_uq');
            $table->foreign('resolved_by')->references('id')->on('users');
            $table->foreign(['tenant_id', 'membership_id'], 'conflicts_membership_fk')->references(['tenant_id', 'id'])->on('tenant_memberships');
            $table->foreign(['tenant_id', 'hearing_a_id'], 'conflicts_a_fk')->references(['tenant_id', 'id'])->on('hearings');
            $table->foreign(['tenant_id', 'hearing_b_id'], 'conflicts_b_fk')->references(['tenant_id', 'id'])->on('hearings');
        });
        DB::statement('ALTER TABLE schedule_conflicts ADD CONSTRAINT conflicts_order_chk CHECK (hearing_a_id < hearing_b_id)');
        DB::statement("ALTER TABLE schedule_conflicts ADD CONSTRAINT conflicts_type_chk CHECK (conflict_type IN ('different_court_same_day','time_overlap'))");
        DB::statement("ALTER TABLE schedule_conflicts ADD CONSTRAINT conflicts_status_chk CHECK (status IN ('open','acknowledged','resolved'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_conflicts');
        Schema::dropIfExists('diary_entries');
        Schema::dropIfExists('hearing_attendees');
        Schema::dropIfExists('hearings');
    }
};
