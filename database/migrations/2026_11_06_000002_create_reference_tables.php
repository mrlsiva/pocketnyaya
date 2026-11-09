<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COURT_TYPES = "('supreme','high','district','tribunal','consumer','family','other')";

    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('name', 100);
            $table->datetimes(6);
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained('states');
            $table->string('name', 120);
            $table->boolean('is_pilot')->default(false);
            $table->datetimes(6);
            $table->unique(['state_id', 'name']);
        });

        Schema::create('practice_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('practice_areas');
            $table->string('slug', 120)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->datetimes(6);
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 80);
            $table->string('native_name', 80)->nullable();
            $table->datetimes(6);
        });

        Schema::create('bar_councils', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->nullable()->constrained('states');
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->datetimes(6);
        });

        Schema::create('courts', function (Blueprint $table) {
            $table->id();
            $table->string('court_type', 20);
            $table->foreignId('parent_court_id')->nullable()->constrained('courts');
            $table->string('name', 200);
            $table->string('ecourts_code', 40)->nullable();
            $table->foreignId('state_id')->constrained('states');
            $table->foreignId('city_id')->nullable()->constrained('cities');
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes(6);
            $table->index(['state_id', 'court_type']);
        });
        DB::statement('ALTER TABLE courts ADD CONSTRAINT courts_type_chk CHECK (court_type IN '.self::COURT_TYPES.')');

        Schema::create('case_types', function (Blueprint $table) {
            $table->id();
            $table->string('court_type', 20);
            $table->string('name', 150);
            $table->string('abbreviation', 30);
            $table->datetimes(6);
            $table->unique(['court_type', 'abbreviation']);
        });
        DB::statement('ALTER TABLE case_types ADD CONSTRAINT case_types_court_chk CHECK (court_type IN '.self::COURT_TYPES.')');
    }

    public function down(): void
    {
        Schema::dropIfExists('case_types');
        Schema::dropIfExists('courts');
        Schema::dropIfExists('bar_councils');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('practice_areas');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
    }
};
