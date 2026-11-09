<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('name', 150);
            // MY design: stored lowercase by the app, unique under a binary collation.
            $table->string('email')->collation('utf8mb4_bin')->nullable()->unique();
            $table->string('phone', 15)->nullable()->unique();
            $table->dateTime('email_verified_at', 6)->nullable();
            $table->dateTime('phone_verified_at', 6)->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->string('status', 12)->default('pending')->index();
            $table->string('locale', 10)->default('en');
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->text('two_factor_secret')->nullable();
            $table->dateTime('last_login_at', 6)->nullable();
            $table->dateTime('erased_at', 6)->nullable();
            // C44: per-user consent key. The foreign key to encryption_key_versions
            // is added by the privacy module, when that table exists.
            $table->pnUuid('consent_key_id')->nullable()->unique();
            $table->text('consent_key_wrapped')->nullable();
            $table->smallInteger('consent_key_kek_version')->nullable();
            $table->date('consent_key_destroy_after')->nullable();
            $table->dateTime('consent_key_destroyed_at', 6)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_chk CHECK (status IN ('pending','active','suspended','erased'))");

        Schema::create('user_devices', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('user_id');
            $table->string('platform', 10);
            $table->text('push_token');
            // Long tokens can't be indexed directly, so uniqueness uses their hash.
            $table->char('push_token_hash', 64)->charset('ascii')->collation('ascii_bin')->storedAs('SHA2(push_token, 256)');
            $table->string('app_version', 30)->nullable();
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->datetimes(6);
            $table->foreign('user_id')->references('id')->on('users');
            $table->unique('push_token_hash');
        });
        DB::statement("ALTER TABLE user_devices ADD CONSTRAINT user_devices_platform_chk CHECK (platform IN ('android','ios','web'))");

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('user_id');
            $table->string('event_key', 80);
            $table->string('channel', 10);
            $table->boolean('enabled')->default(true);
            $table->datetimes(6);
            $table->foreign('user_id')->references('id')->on('users');
            $table->unique(['user_id', 'event_key', 'channel'], 'notification_prefs_uq');
        });
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_prefs_channel_chk CHECK (channel IN ('email','sms','in_app','push'))");

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->pnUuid('updated_by')->nullable();
            $table->datetimes(6);
            $table->foreign('updated_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('users');
    }
};
