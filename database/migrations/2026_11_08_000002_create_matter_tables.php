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
        Schema::create('matters', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('branch_id')->nullable();
            $table->pnUuid('client_id');
            $table->string('matter_number', 30);
            $table->string('title', 200);
            $table->string('matter_type', 20);
            $table->foreignId('practice_area_id')->nullable()->constrained('practice_areas');
            $table->foreignId('court_id')->nullable()->constrained('courts');
            $table->foreignId('case_type_id')->nullable()->constrained('case_types');
            $table->string('case_number', 40)->nullable();
            $table->smallInteger('case_year')->nullable();
            $table->char('cnr_number', 16)->nullable()->index();
            $table->date('filing_date')->nullable();
            $table->string('stage', 80)->nullable();
            $table->string('status', 12)->default('unallocated');
            $table->string('priority', 8)->default('normal');
            $table->text('description')->nullable();
            $table->pnUuid('practice_group_id')->nullable();
            $table->dateTime('opened_at', 6);
            $table->dateTime('closed_at', 6)->nullable();
            $table->pnUuid('created_by');
            $table->json('metadata')->default(new Expression("'{}'"));
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'matter_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'client_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign(['tenant_id', 'client_id'], 'matters_client_fk')->references(['tenant_id', 'id'])->on('clients');
            $table->foreign(['tenant_id', 'branch_id'], 'matters_branch_fk')->references(['tenant_id', 'id'])->on('tenant_branches');
            $table->foreign(['tenant_id', 'practice_group_id'], 'matters_group_fk')->references(['tenant_id', 'id'])->on('practice_groups');
        });
        DB::statement("ALTER TABLE matters ADD CONSTRAINT matters_type_chk CHECK (matter_type IN ('litigation','advisory','drafting','creator_retainer','other'))");
        DB::statement("ALTER TABLE matters ADD CONSTRAINT matters_status_chk CHECK (status IN ('unallocated','open','on_hold','disposed','closed','archived'))");
        DB::statement("ALTER TABLE matters ADD CONSTRAINT matters_priority_chk CHECK (priority IN ('low','normal','high','urgent'))");

        Schema::create('matter_assignments', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('matter_id');
            $table->pnUuid('membership_id');
            $table->string('role_on_matter', 15);
            $table->pnUuid('assigned_by');
            $table->dateTime('assigned_at', 6);
            $table->dateTime('unassigned_at', 6)->nullable();
            $table->datetimes(6);
            $table->char('active_membership_id', 36)->charset('ascii')->collation('ascii_bin')
                ->storedAs('IF(unassigned_at IS NULL, membership_id, NULL)');

            $table->unique(['matter_id', 'active_membership_id'], 'assignments_active_uq');
            $table->index(['tenant_id', 'membership_id']);
            $table->foreign('assigned_by')->references('id')->on('users');
            $table->foreign(['tenant_id', 'matter_id'], 'assignments_matter_fk')->references(['tenant_id', 'id'])->on('matters');
            $table->foreign(['tenant_id', 'membership_id'], 'assignments_membership_fk')->references(['tenant_id', 'id'])->on('tenant_memberships');
        });
        DB::statement("ALTER TABLE matter_assignments ADD CONSTRAINT assignments_role_chk CHECK (role_on_matter IN ('lead','associate','junior','clerk','finance_viewer'))");

        Schema::create('matter_parties', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('matter_id');
            $table->string('party_role', 12);
            $table->string('name', 200);
            $table->boolean('is_our_client')->default(false);
            $table->pnUuid('client_id')->nullable();
            $table->string('counsel_name', 150)->nullable();
            $table->text('contact')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->datetimes(6);

            $table->foreign(['tenant_id', 'matter_id'], 'parties_matter_fk')->references(['tenant_id', 'id'])->on('matters');
            $table->foreign(['tenant_id', 'client_id'], 'parties_client_fk')->references(['tenant_id', 'id'])->on('clients');
        });
        DB::statement("ALTER TABLE matter_parties ADD CONSTRAINT parties_role_chk CHECK (party_role IN ('petitioner','respondent','appellant','plaintiff','defendant','complainant','accused','other'))");

        Schema::create('matter_tasks', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('matter_id')->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->pnUuid('assigned_to_membership_id')->nullable();
            $table->pnUuid('assigned_by');
            $table->dateTime('due_at', 6)->nullable();
            $table->string('priority', 8)->default('normal');
            $table->string('status', 12)->default('todo');
            $table->dateTime('completed_at', 6)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->index(['tenant_id', 'assigned_to_membership_id', 'status'], 'tasks_assignee_idx');
            $table->foreign('assigned_by')->references('id')->on('users');
            $table->foreign(['tenant_id', 'matter_id'], 'tasks_matter_fk')->references(['tenant_id', 'id'])->on('matters');
            $table->foreign(['tenant_id', 'assigned_to_membership_id'], 'tasks_membership_fk')->references(['tenant_id', 'id'])->on('tenant_memberships');
        });
        DB::statement("ALTER TABLE matter_tasks ADD CONSTRAINT tasks_status_chk CHECK (status IN ('todo','in_progress','done','cancelled'))");
        DB::statement("ALTER TABLE matter_tasks ADD CONSTRAINT tasks_priority_chk CHECK (priority IN ('low','normal','high','urgent'))");

        Schema::create('matter_notes', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('matter_id');
            $table->pnUuid('author_user_id');
            $table->text('body');
            $table->string('visibility', 10)->default('team');
            $table->boolean('is_pinned')->default(false);
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->foreign('author_user_id')->references('id')->on('users');
            $table->foreign(['tenant_id', 'matter_id'], 'notes_matter_fk')->references(['tenant_id', 'id'])->on('matters');
        });
        DB::statement("ALTER TABLE matter_notes ADD CONSTRAINT notes_visibility_chk CHECK (visibility IN ('team','private','partners'))");

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'matter_id'], 'doc_matter_fk')->references(['tenant_id', 'id'])->on('matters');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign('doc_matter_fk');
            $table->dropIndex('doc_matter_fk');
        });
        Schema::dropIfExists('matter_notes');
        Schema::dropIfExists('matter_tasks');
        Schema::dropIfExists('matter_parties');
        Schema::dropIfExists('matter_assignments');
        Schema::dropIfExists('matters');
    }
};
