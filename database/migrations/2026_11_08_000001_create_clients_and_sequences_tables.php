<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // C16: gap-free numbering through row locks, never database sequences.
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id')->nullable();
            $table->string('sequence_key', 40);
            $table->string('period', 10);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->datetimes(6);
            $table->string('tenant_key', 36)->charset('ascii')->collation('ascii_bin')->nullable(); // set by trigger (MariaDB 10.11)

            $table->unique(['tenant_key', 'sequence_key', 'period'], 'number_sequences_uq');
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        foreach (['INSERT' => 'bi', 'UPDATE' => 'bu'] as $event => $suffix) {
            DB::unprepared("CREATE TRIGGER number_sequences_keys_{$suffix} BEFORE {$event} ON number_sequences FOR EACH ROW SET NEW.tenant_key = COALESCE(NEW.tenant_id, 'PLATFORM')");
        }

        Schema::create('clients', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('customer_user_id')->nullable();
            $table->string('client_type', 12);
            $table->string('name', 200);
            $table->string('email')->collation('utf8mb4_bin')->nullable();
            $table->text('phone')->nullable();
            $table->char('phone_bidx', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->text('address')->nullable();
            $table->char('state_code', 2)->charset('ascii')->collation('ascii_bin');
            $table->string('gstin', 15)->nullable();
            $table->text('pan')->nullable();
            $table->boolean('is_business_entity')->default(false);
            $table->string('turnover_category', 16)->nullable();
            $table->text('notes')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'customer_user_id'], 'clients_customer_uq');
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'phone_bidx']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_user_id')->references('id')->on('users');
            $table->foreign('state_code')->references('code')->on('states');
        });
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_type_chk CHECK (client_type IN ('individual','business','government'))");
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_turnover_chk CHECK (turnover_category IN ('unknown','below_threshold','above_threshold'))");

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'client_id'], 'doc_client_fk')->references(['tenant_id', 'id'])->on('clients');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign('doc_client_fk');
            $table->dropIndex('doc_client_fk');
        });
        Schema::dropIfExists('clients');
        Schema::dropIfExists('number_sequences');
    }
};
