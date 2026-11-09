<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mediator_profiles', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('user_id')->unique();
            $table->string('accreditation_body', 150);
            // Nullable only so erasure can release it (C44 section 1.4).
            $table->string('accreditation_number', 60)->nullable();
            $table->date('accreditation_valid_until')->nullable();
            $table->string('verification_status', 10)->default('pending');
            $table->boolean('is_available')->default(false);
            $table->text('fee_terms')->nullable();
            $table->dateTime('erased_at', 6)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->foreign('user_id')->references('id')->on('users');
            $table->unique(['accreditation_body', 'accreditation_number'], 'mediator_accreditation_uq');
        });
        DB::statement("ALTER TABLE mediator_profiles ADD CONSTRAINT mediator_verification_chk CHECK (verification_status IN ('pending','verified','rejected','suspended'))");
        DB::statement('ALTER TABLE mediator_profiles ADD CONSTRAINT mediator_accreditation_chk CHECK (erased_at IS NOT NULL OR accreditation_number IS NOT NULL)');

        Schema::create('mediator_languages', function (Blueprint $table) {
            $table->pnUuid('mediator_profile_id');
            $table->foreignId('language_id')->constrained('languages');
            $table->datetimes(6);

            $table->primary(['mediator_profile_id', 'language_id']);
            $table->index(['language_id', 'mediator_profile_id'], 'mediator_languages_reverse_idx');
            $table->foreign('mediator_profile_id')->references('id')->on('mediator_profiles')->cascadeOnDelete();
        });

        // Category codes match odr_disputes.category in the approved design.
        Schema::create('mediator_categories', function (Blueprint $table) {
            $table->pnUuid('mediator_profile_id');
            $table->string('category', 20);
            $table->datetimes(6);

            $table->primary(['mediator_profile_id', 'category']);
            $table->index(['category', 'mediator_profile_id'], 'mediator_categories_reverse_idx');
            $table->foreign('mediator_profile_id')->references('id')->on('mediator_profiles')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE mediator_categories ADD CONSTRAINT mediator_category_chk CHECK (category IN ('small_claims','landlord_tenant','ecommerce','service','creator_brand','other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('mediator_categories');
        Schema::dropIfExists('mediator_languages');
        Schema::dropIfExists('mediator_profiles');
    }
};
