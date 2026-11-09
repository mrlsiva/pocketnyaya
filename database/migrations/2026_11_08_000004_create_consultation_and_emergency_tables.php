<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two-sided: the customer owns it, and the lawyer's tenant receives it.
        Schema::create('consultation_requests', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('customer_user_id');
            $table->pnUuid('advocate_profile_id');
            $table->pnUuid('tenant_id');
            $table->pnUuid('advocate_membership_id');
            $table->pnUuid('client_id')->nullable();
            $table->string('mode', 10);
            $table->string('subject', 200);
            $table->text('description');
            $table->json('preferred_slots')->nullable();
            $table->dateTime('scheduled_at', 6)->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->string('status', 10)->default('requested');
            $table->text('lawyer_private_notes')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['customer_user_id', 'status']);
            $table->foreign('customer_user_id')->references('id')->on('users');
            $table->foreign('advocate_profile_id')->references('id')->on('advocate_profiles');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign(['tenant_id', 'advocate_membership_id'], 'consultations_membership_fk')->references(['tenant_id', 'id'])->on('tenant_memberships');
            $table->foreign(['tenant_id', 'client_id'], 'consultations_client_fk')->references(['tenant_id', 'id'])->on('clients');
        });
        DB::statement("ALTER TABLE consultation_requests ADD CONSTRAINT consultations_mode_chk CHECK (mode IN ('chat','video','call','in_person'))");
        DB::statement("ALTER TABLE consultation_requests ADD CONSTRAINT consultations_status_chk CHECK (status IN ('requested','accepted','declined','scheduled','completed','cancelled','expired'))");

        Schema::create('consultation_messages', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('consultation_request_id')->index();
            $table->pnUuid('sender_user_id');
            $table->text('body');
            $table->pnUuid('document_id')->nullable();
            $table->dateTime('read_at', 6)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();

            $table->foreign('consultation_request_id', 'messages_consultation_fk')->references('id')->on('consultation_requests');
            $table->foreign('sender_user_id', 'messages_sender_fk')->references('id')->on('users');
            $table->foreign('document_id', 'messages_document_fk')->references('id')->on('documents');
        });
        // Append-only, except marking a message as read.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER consultation_messages_guard_upd BEFORE UPDATE ON consultation_messages FOR EACH ROW
BEGIN
    IF NEW.body <> OLD.body OR NEW.sender_user_id <> OLD.sender_user_id OR NEW.consultation_request_id <> OLD.consultation_request_id OR NOT (NEW.document_id <=> OLD.document_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_APPEND_ONLY: consultation messages cannot be changed';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER consultation_messages_guard_del BEFORE DELETE ON consultation_messages FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_APPEND_ONLY: consultation messages cannot be deleted'
SQL);

        Schema::create('emergency_responders', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('advocate_profile_id')->nullable();
            $table->string('organization_name', 200)->nullable();
            $table->text('contact_phone');
            $table->foreignId('city_id')->constrained('cities');
            $table->boolean('is_active')->default(true);
            $table->json('availability')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->foreign('advocate_profile_id')->references('id')->on('advocate_profiles');
        });

        // No soft delete: a retention rule applies instead.
        Schema::create('emergency_requests', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('customer_user_id')->nullable()->index();
            $table->string('category', 20);
            $table->foreignId('city_id')->constrained('cities');
            $table->foreignId('preferred_language_id')->nullable()->constrained('languages');
            $table->text('contact_phone');
            $table->text('description');
            $table->string('priority', 2)->default('p2');
            $table->string('status', 12)->default('open');
            $table->pnUuid('assigned_responder_id')->nullable();
            $table->dateTime('acknowledged_at', 6)->nullable();
            $table->dateTime('resolved_at', 6)->nullable();
            $table->dateTime('sla_due_at', 6);
            $table->datetimes(6);

            $table->index(['status', 'priority', 'created_at']);
            $table->index(['assigned_responder_id', 'status']);
            $table->foreign('customer_user_id')->references('id')->on('users');
            $table->foreign('assigned_responder_id')->references('id')->on('emergency_responders');
        });
        DB::statement("ALTER TABLE emergency_requests ADD CONSTRAINT emergency_category_chk CHECK (category IN ('domestic_violence','harassment','protection_order','other'))");
        DB::statement("ALTER TABLE emergency_requests ADD CONSTRAINT emergency_priority_chk CHECK (priority IN ('p1','p2','p3'))");
        DB::statement("ALTER TABLE emergency_requests ADD CONSTRAINT emergency_status_chk CHECK (status IN ('open','acknowledged','assigned','referred','resolved','closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_requests');
        Schema::dropIfExists('emergency_responders');
        DB::unprepared('DROP TRIGGER IF EXISTS consultation_messages_guard_upd');
        DB::unprepared('DROP TRIGGER IF EXISTS consultation_messages_guard_del');
        Schema::dropIfExists('consultation_messages');
        Schema::dropIfExists('consultation_requests');
    }
};
