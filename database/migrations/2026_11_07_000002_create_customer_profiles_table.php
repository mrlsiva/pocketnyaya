<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The citizen's own profile, platform-level. Created on first use of citizen features.
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('user_id')->unique();
            $table->string('display_name', 150)->nullable();
            $table->foreignId('city_id')->nullable()->constrained('cities');
            $table->foreignId('preferred_language_id')->nullable()->constrained('languages');
            $table->boolean('is_business')->default(false);
            $table->string('business_name', 200)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->text('address')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
