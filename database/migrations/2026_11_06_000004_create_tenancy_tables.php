<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('type', 10);
            $table->string('name', 200);
            $table->string('slug', 120)->unique();
            $table->string('legal_name', 200)->nullable();
            $table->pnUuid('owner_user_id')->nullable();
            $table->text('pan')->nullable();
            $table->string('gst_registration_type', 15);
            $table->string('gstin', 15)->nullable();
            $table->char('state_code', 2)->charset('ascii')->collation('ascii_bin');
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->foreignId('city_id')->nullable()->constrained('cities');
            $table->string('pincode', 6)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 15)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('status', 10)->default('active');
            $table->boolean('ml_training_opt_in')->default(false);
            $table->json('settings')->default(new Expression("'{}'"));
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            // Generated columns replace PostgreSQL partial unique indexes (MY design).
            $table->tinyInteger('platform_marker')->storedAs("IF(type = 'platform', 1, NULL)");
            $table->char('solo_owner_key', 36)->charset('ascii')->collation('ascii_bin')
                ->storedAs("IF(type = 'solo' AND deleted_at IS NULL, owner_user_id, NULL)");
            $table->string('active_gstin', 15)->storedAs('IF(deleted_at IS NULL, gstin, NULL)');

            $table->foreign('owner_user_id')->references('id')->on('users');
            $table->foreign('state_code')->references('code')->on('states');
            $table->unique('platform_marker');
            $table->unique('solo_owner_key');
            $table->unique('active_gstin');
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_type_chk CHECK (type IN ('platform','solo','firm'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_gst_type_chk CHECK (gst_registration_type IN ('unregistered','regular','composition'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_chk CHECK (status IN ('active','suspended','closed'))");

        Schema::create('tenant_branches', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->string('name', 150);
            $table->boolean('is_head_office')->default(false);
            $table->string('gstin', 15)->nullable();
            $table->char('state_code', 2)->charset('ascii')->collation('ascii_bin');
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->foreignId('city_id')->constrained('cities');
            $table->string('pincode', 6)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);
            $table->char('head_office_key', 36)->charset('ascii')->collation('ascii_bin')
                ->storedAs('IF(is_head_office = 1 AND deleted_at IS NULL, tenant_id, NULL)');

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('state_code')->references('code')->on('states');
            $table->unique(['tenant_id', 'id']);
            $table->unique('head_office_key');
        });

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('user_id');
            $table->pnUuid('branch_id')->nullable();
            $table->string('designation', 80)->nullable();
            $table->pnUuid('supervisor_membership_id')->nullable();
            $table->string('status', 10)->default('invited');
            $table->integer('max_active_matters')->nullable();
            $table->dateTime('joined_at', 6)->nullable();
            $table->dateTime('left_at', 6)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign(['tenant_id', 'branch_id'], 'memberships_branch_fk')
                ->references(['tenant_id', 'id'])->on('tenant_branches');
            $table->foreign(['tenant_id', 'supervisor_membership_id'], 'memberships_supervisor_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships');
        });
        DB::statement("ALTER TABLE tenant_memberships ADD CONSTRAINT memberships_status_chk CHECK (status IN ('invited','active','suspended','left'))");

        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->string('email')->collation('utf8mb4_bin');
            $table->string('role_name', 60);
            $table->pnUuid('branch_id')->nullable();
            $table->pnUuid('invited_by');
            $table->char('token_hash', 64)->charset('ascii')->collation('ascii_bin')->unique();
            $table->dateTime('expires_at', 6);
            $table->dateTime('accepted_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->datetimes(6);
            $table->string('pending_email')->collation('utf8mb4_bin')
                ->storedAs('IF(accepted_at IS NULL AND revoked_at IS NULL, email, NULL)');

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'pending_email'], 'invitations_pending_uq');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('invited_by')->references('id')->on('users');
            $table->foreign(['tenant_id', 'branch_id'], 'invitations_branch_fk')
                ->references(['tenant_id', 'id'])->on('tenant_branches');
        });

        Schema::create('practice_groups', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->string('name', 150);
            $table->foreignId('practice_area_id')->nullable()->constrained('practice_areas');
            $table->pnUuid('lead_membership_id')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign(['tenant_id', 'lead_membership_id'], 'practice_groups_lead_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships');
        });

        Schema::create('practice_group_members', function (Blueprint $table) {
            $table->pnUuid('practice_group_id');
            $table->pnUuid('membership_id');
            $table->pnUuid('tenant_id');
            $table->datetimes(6);

            $table->primary(['practice_group_id', 'membership_id']);
            $table->foreign(['tenant_id', 'practice_group_id'], 'pgm_group_fk')
                ->references(['tenant_id', 'id'])->on('practice_groups');
            $table->foreign(['tenant_id', 'membership_id'], 'pgm_membership_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships');
        });

        Schema::create('membership_practice_areas', function (Blueprint $table) {
            $table->pnUuid('membership_id');
            $table->foreignId('practice_area_id')->constrained('practice_areas');
            $table->pnUuid('tenant_id');
            $table->datetimes(6);

            $table->primary(['membership_id', 'practice_area_id']);
            $table->foreign(['tenant_id', 'membership_id'], 'mpa_membership_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships');
        });

        Schema::create('membership_availability', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('membership_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('availability', 12);
            $table->text('note')->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'membership_id'], 'availability_membership_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships');
        });
        DB::statement("ALTER TABLE membership_availability ADD CONSTRAINT availability_value_chk CHECK (availability IN ('leave','partial','unavailable'))");

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('billing_period', 10);
            $table->decimal('price', 14, 2)->nullable();
            $table->json('features');
            $table->json('limits');
            $table->boolean('is_active')->default(true);
            $table->datetimes(6);
        });
        DB::statement("ALTER TABLE subscription_plans ADD CONSTRAINT plans_period_chk CHECK (billing_period IN ('monthly','yearly'))");

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('plan_id');
            $table->string('status', 10);
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6)->nullable();
            $table->dateTime('trial_ends_at', 6)->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('plan_id')->references('id')->on('subscription_plans');
        });
        DB::statement("ALTER TABLE tenant_subscriptions ADD CONSTRAINT subscriptions_status_chk CHECK (status IN ('trialing','active','past_due','cancelled','expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('membership_availability');
        Schema::dropIfExists('membership_practice_areas');
        Schema::dropIfExists('practice_group_members');
        Schema::dropIfExists('practice_groups');
        Schema::dropIfExists('tenant_invitations');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenant_branches');
        Schema::dropIfExists('tenants');
    }
};
