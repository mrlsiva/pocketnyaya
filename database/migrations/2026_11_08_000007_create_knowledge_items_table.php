<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Search covers metadata and the summary only. Encrypted file content isn't indexed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->string('item_type', 12);
            $table->string('title', 200);
            $table->foreignId('practice_area_id')->nullable();
            $table->foreignId('court_id')->nullable();
            $table->json('tags')->nullable();
            $table->text('summary')->nullable();
            $table->pnUuid('document_id')->nullable();
            $table->pnUuid('source_matter_id')->nullable();
            $table->string('status', 10)->default('draft');
            $table->pnUuid('created_by');
            $table->pnUuid('approved_by')->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->index(['tenant_id', 'item_type', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('practice_area_id')->references('id')->on('practice_areas');
            $table->foreign('court_id')->references('id')->on('courts');
            $table->foreign(['tenant_id', 'document_id'], 'knowledge_document_fk')->references(['tenant_id', 'id'])->on('documents');
            $table->foreign(['tenant_id', 'source_matter_id'], 'knowledge_matter_fk')->references(['tenant_id', 'id'])->on('matters');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('approved_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE knowledge_items ADD CONSTRAINT knowledge_type_chk CHECK (item_type IN ('pleading','template','opinion','judgment','precedent','checklist'))");
        DB::statement("ALTER TABLE knowledge_items ADD CONSTRAINT knowledge_status_chk CHECK (status IN ('draft','approved','archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_items');
    }
};
