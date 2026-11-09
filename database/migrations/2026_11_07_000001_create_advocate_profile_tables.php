<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level public identity. No tenant_id, and by design no rating,
        // review, score, tier, featured or sponsored columns.
        Schema::create('advocate_profiles', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('user_id')->unique();
            $table->foreignId('bar_council_id')->constrained('bar_councils');
            // Nullable only so erasure can release it (C44 section 1.4).
            $table->string('enrollment_number', 40)->nullable();
            // "Years at the Bar" is calculated from this date, never stored.
            $table->date('enrollment_date');
            $table->text('bio')->nullable();
            $table->foreignId('city_id')->constrained('cities');
            $table->text('office_address')->nullable();
            $table->string('verification_status', 10)->default('draft');
            $table->dateTime('verified_at', 6)->nullable();
            $table->pnUuid('verified_by')->nullable();
            $table->boolean('is_directory_listed')->default(false);
            $table->string('bio_screening_status', 10)->default('pending');
            $table->dateTime('erased_at', 6)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('verified_by')->references('id')->on('users');
            // Includes soft-deleted rows: enrollment numbers are identity and aren't reused.
            $table->unique(['bar_council_id', 'enrollment_number'], 'advocate_enrollment_uq');
            $table->index(['city_id', 'verification_status', 'is_directory_listed'], 'advocate_directory_idx');
        });
        DB::statement("ALTER TABLE advocate_profiles ADD CONSTRAINT advocate_verification_chk CHECK (verification_status IN ('draft','pending','verified','rejected','suspended'))");
        DB::statement("ALTER TABLE advocate_profiles ADD CONSTRAINT advocate_screening_chk CHECK (bio_screening_status IN ('pending','passed','flagged','rejected'))");
        DB::statement('ALTER TABLE advocate_profiles ADD CONSTRAINT advocate_enrollment_chk CHECK (erased_at IS NOT NULL OR enrollment_number IS NOT NULL)');

        Schema::create('advocate_qualifications', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('advocate_profile_id');
            $table->string('degree', 120);
            $table->string('institution', 200);
            $table->smallInteger('year_of_passing');
            $table->datetimes(6);

            $table->foreign('advocate_profile_id')->references('id')->on('advocate_profiles');
        });

        Schema::create('advocate_practice_areas', function (Blueprint $table) {
            $table->pnUuid('advocate_profile_id');
            $table->foreignId('practice_area_id')->constrained('practice_areas');
            $table->datetimes(6);

            $table->primary(['advocate_profile_id', 'practice_area_id']);
            $table->index(['practice_area_id', 'advocate_profile_id'], 'advocate_practice_areas_reverse_idx');
            $table->foreign('advocate_profile_id')->references('id')->on('advocate_profiles')->cascadeOnDelete();
        });

        Schema::create('advocate_languages', function (Blueprint $table) {
            $table->pnUuid('advocate_profile_id');
            $table->foreignId('language_id')->constrained('languages');
            $table->datetimes(6);

            $table->primary(['advocate_profile_id', 'language_id']);
            $table->index(['language_id', 'advocate_profile_id'], 'advocate_languages_reverse_idx');
            $table->foreign('advocate_profile_id')->references('id')->on('advocate_profiles')->cascadeOnDelete();
        });

        Schema::create('advocate_courts', function (Blueprint $table) {
            $table->pnUuid('advocate_profile_id');
            $table->foreignId('court_id')->constrained('courts');
            $table->datetimes(6);

            $table->primary(['advocate_profile_id', 'court_id']);
            $table->index(['court_id', 'advocate_profile_id'], 'advocate_courts_reverse_idx');
            $table->foreign('advocate_profile_id')->references('id')->on('advocate_profiles')->cascadeOnDelete();
        });

        Schema::create('verification_requests', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('advocate_profile_id')->index();
            $table->string('status', 15)->default('submitted');
            $table->dateTime('submitted_at', 6);
            $table->pnUuid('assigned_to')->nullable();
            $table->pnUuid('reviewed_by')->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->json('checklist');
            $table->text('decision_notes')->nullable();
            $table->datetimes(6);

            $table->index(['status', 'submitted_at']);
            $table->foreign('advocate_profile_id')->references('id')->on('advocate_profiles');
            $table->foreign('assigned_to')->references('id')->on('users');
            $table->foreign('reviewed_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE verification_requests ADD CONSTRAINT verification_requests_status_chk CHECK (status IN ('submitted','in_review','info_requested','approved','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_requests');
        Schema::dropIfExists('advocate_courts');
        Schema::dropIfExists('advocate_languages');
        Schema::dropIfExists('advocate_practice_areas');
        Schema::dropIfExists('advocate_qualifications');
        Schema::dropIfExists('advocate_profiles');
    }
};
