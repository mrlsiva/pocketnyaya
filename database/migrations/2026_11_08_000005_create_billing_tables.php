<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing and tax. Rates are data, so 18% is never hardcoded. Issued invoices are
 * cancelled, never deleted, and keep a snapshot of the tax rule used.
 * There are no commission, lead or referral tables, by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id')->nullable();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('sac_code', 8)->default('9982');
            $table->string('supplier_registration', 12);
            $table->string('recipient_type', 12);
            $table->string('recipient_turnover', 16);
            $table->string('supply_type', 12);
            $table->string('charge_mechanism', 8);
            $table->decimal('cgst_rate', 5, 2)->default(0);
            $table->decimal('sgst_rate', 5, 2)->default(0);
            $table->decimal('igst_rate', 5, 2)->default(0);
            $table->decimal('cess_rate', 5, 2)->default(0);
            $table->string('invoice_type', 14);
            $table->integer('priority')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('legal_reference')->nullable();
            $table->pnUuid('drafted_by')->nullable();
            $table->pnUuid('approved_by')->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->boolean('is_active')->default(false);
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);
            $table->string('tenant_key', 36)->charset('ascii')->collation('ascii_bin')->storedAs("COALESCE(tenant_id, 'PLATFORM')");

            $table->unique(['tenant_key', 'code', 'effective_from'], 'tax_rules_code_uq');
            $table->index(['is_active', 'effective_from']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('drafted_by')->references('id')->on('users');
            $table->foreign('approved_by')->references('id')->on('users');
        });
        foreach ([
            "supplier_registration IN ('registered','unregistered','any')",
            "recipient_type IN ('individual','business','government','any')",
            "recipient_turnover IN ('below_threshold','above_threshold','any')",
            "supply_type IN ('intra_state','inter_state','any')",
            "charge_mechanism IN ('forward','reverse','exempt','nil')",
            "invoice_type IN ('tax_invoice','bill_of_supply')",
            'cgst_rate >= 0 AND sgst_rate >= 0 AND igst_rate >= 0 AND cess_rate >= 0',
            "charge_mechanism NOT IN ('exempt','nil') OR (cgst_rate = 0 AND sgst_rate = 0 AND igst_rate = 0)",
        ] as $i => $check) {
            DB::statement("ALTER TABLE tax_rules ADD CONSTRAINT tax_rules_chk_{$i} CHECK ({$check})");
        }

        Schema::create('retainer_plans', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('monthly_fee', 14, 2);
            $table->json('scope_items')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        DB::statement('ALTER TABLE retainer_plans ADD CONSTRAINT retainer_plans_fee_chk CHECK (monthly_fee >= 0)');

        Schema::create('fee_arrangements', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('client_id');
            $table->pnUuid('matter_id')->nullable();
            $table->string('arrangement_type', 18);
            $table->decimal('hourly_rate', 14, 2)->nullable();
            $table->decimal('retainer_amount', 14, 2)->nullable();
            $table->pnUuid('retainer_plan_id')->nullable();
            $table->smallInteger('billing_day')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 8)->default('active');
            $table->text('notes')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'client_id'], 'fee_client_fk')->references(['tenant_id', 'id'])->on('clients');
            $table->foreign(['tenant_id', 'matter_id'], 'fee_matter_fk')->references(['tenant_id', 'id'])->on('matters');
            $table->foreign(['tenant_id', 'retainer_plan_id'], 'fee_plan_fk')->references(['tenant_id', 'id'])->on('retainer_plans');
        });
        DB::statement("ALTER TABLE fee_arrangements ADD CONSTRAINT fee_type_chk CHECK (arrangement_type IN ('hourly','monthly_retainer','lump_sum','event_based','creator_retainer'))");
        DB::statement("ALTER TABLE fee_arrangements ADD CONSTRAINT fee_status_chk CHECK (status IN ('active','paused','ended'))");
        DB::statement('ALTER TABLE fee_arrangements ADD CONSTRAINT fee_amounts_chk CHECK ((hourly_rate IS NULL OR hourly_rate >= 0) AND (retainer_amount IS NULL OR retainer_amount >= 0))');

        Schema::create('fee_schedule_items', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('fee_arrangement_id');
            $table->string('event_type', 12);
            $table->string('description', 200);
            $table->decimal('amount', 14, 2);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'fee_arrangement_id'], 'schedule_fee_fk')->references(['tenant_id', 'id'])->on('fee_arrangements');
        });
        DB::statement("ALTER TABLE fee_schedule_items ADD CONSTRAINT schedule_type_chk CHECK (event_type IN ('drafting','appearance','consultation','filing','conference','other'))");
        DB::statement('ALTER TABLE fee_schedule_items ADD CONSTRAINT schedule_amount_chk CHECK (amount >= 0)');

        Schema::create('retainer_work_items', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('fee_arrangement_id');
            $table->pnUuid('matter_id')->nullable();
            $table->string('work_type', 20);
            $table->string('title', 200);
            $table->string('status', 12)->default('open');
            $table->dateTime('due_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->foreign(['tenant_id', 'fee_arrangement_id'], 'work_fee_fk')->references(['tenant_id', 'id'])->on('fee_arrangements');
            $table->foreign(['tenant_id', 'matter_id'], 'work_matter_fk')->references(['tenant_id', 'id'])->on('matters');
        });
        DB::statement("ALTER TABLE retainer_work_items ADD CONSTRAINT work_type_chk CHECK (work_type IN ('brand_deal_review','ip_filing','non_compete_audit','dpdp_check','other'))");
        DB::statement("ALTER TABLE retainer_work_items ADD CONSTRAINT work_status_chk CHECK (status IN ('open','in_progress','done'))");

        Schema::create('invoice_series', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->string('prefix', 8);
            $table->char('financial_year', 7);
            $table->integer('next_number')->default(1);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'prefix', 'financial_year'], 'invoice_series_uq');
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('client_id');
            $table->pnUuid('matter_id')->nullable();
            $table->pnUuid('invoice_series_id')->nullable();
            $table->string('invoice_number', 16)->nullable();
            $table->string('invoice_type', 14)->default('tax_invoice');
            $table->pnUuid('original_invoice_id')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->json('supplier_snapshot')->nullable();
            $table->json('recipient_snapshot')->nullable();
            $table->char('place_of_supply', 2)->charset('ascii')->collation('ascii_bin');
            $table->string('charge_mechanism', 8)->default('forward');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('round_off', 4, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('tds_deducted', 14, 2)->default(0);
            $table->decimal('balance_due', 14, 2)->default(0);
            $table->string('status', 15)->default('draft');
            $table->dateTime('issued_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->text('cancel_reason')->nullable();
            $table->pnUuid('pdf_document_id')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'invoice_number']);
            $table->index(['tenant_id', 'status', 'due_date']);
            $table->foreign(['tenant_id', 'client_id'], 'invoices_client_fk')->references(['tenant_id', 'id'])->on('clients');
            $table->foreign(['tenant_id', 'matter_id'], 'invoices_matter_fk')->references(['tenant_id', 'id'])->on('matters');
            $table->foreign(['tenant_id', 'invoice_series_id'], 'invoices_series_fk')->references(['tenant_id', 'id'])->on('invoice_series');
            $table->foreign(['tenant_id', 'original_invoice_id'], 'invoices_original_fk')->references(['tenant_id', 'id'])->on('invoices');
            $table->foreign(['tenant_id', 'pdf_document_id'], 'invoices_pdf_fk')->references(['tenant_id', 'id'])->on('documents');
            $table->foreign('place_of_supply')->references('code')->on('states');
        });
        foreach ([
            "invoice_type IN ('tax_invoice','bill_of_supply','proforma','credit_note')",
            "charge_mechanism IN ('forward','reverse','exempt','mixed')",
            "status IN ('draft','issued','partially_paid','paid','cancelled','written_off')",
            'subtotal >= 0 AND cgst_amount >= 0 AND sgst_amount >= 0 AND igst_amount >= 0 AND total_tax >= 0 AND total_amount >= 0 AND amount_paid >= 0 AND tds_deducted >= 0',
            'round_off BETWEEN -1 AND 1',
            'total_amount = subtotal + total_tax + round_off',
            'balance_due = total_amount - amount_paid - tds_deducted AND balance_due >= 0',
            "invoice_number IS NULL OR invoice_number REGEXP '^[A-Za-z0-9/-]{1,16}$'",
        ] as $i => $check) {
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_chk_{$i} CHECK ({$check})");
        }

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('invoice_id');
            $table->string('line_type', 18);
            $table->pnUuid('fee_arrangement_id')->nullable();
            $table->pnUuid('fee_schedule_item_id')->nullable();
            $table->string('description', 500);
            $table->string('sac_code', 8)->default('9982');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->pnUuid('tax_rule_id')->nullable();
            $table->json('tax_rule_snapshot')->nullable();
            $table->string('charge_mechanism', 8)->default('forward');
            $table->decimal('cgst_rate', 5, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_rate', 5, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_rate', 5, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'invoice_id'], 'lines_invoice_fk')->references(['tenant_id', 'id'])->on('invoices');
            $table->foreign(['tenant_id', 'fee_arrangement_id'], 'lines_fee_fk')->references(['tenant_id', 'id'])->on('fee_arrangements');
            $table->foreign(['tenant_id', 'fee_schedule_item_id'], 'lines_schedule_fk')->references(['tenant_id', 'id'])->on('fee_schedule_items');
            $table->foreign('tax_rule_id', 'lines_tax_rule_fk')->references('id')->on('tax_rules');
        });
        foreach ([
            "line_type IN ('professional_fee','time','event','retainer','expense')",
            "charge_mechanism IN ('forward','reverse','exempt','nil')",
            'quantity > 0 AND unit_price >= 0 AND amount >= 0 AND cgst_amount >= 0 AND sgst_amount >= 0 AND igst_amount >= 0 AND line_total >= 0',
            'igst_amount = 0 OR (cgst_amount = 0 AND sgst_amount = 0)',
            "charge_mechanism NOT IN ('exempt','nil') OR (cgst_amount = 0 AND sgst_amount = 0 AND igst_amount = 0)",
        ] as $i => $check) {
            DB::statement("ALTER TABLE invoice_lines ADD CONSTRAINT lines_chk_{$i} CHECK ({$check})");
        }

        Schema::create('time_entries', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('matter_id');
            $table->pnUuid('membership_id');
            $table->date('work_date');
            $table->integer('minutes');
            $table->string('description', 500);
            $table->boolean('is_billable')->default(true);
            $table->decimal('rate', 14, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('status', 12)->default('draft');
            $table->pnUuid('invoice_line_id')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->index(['tenant_id', 'matter_id', 'status']);
            $table->foreign(['tenant_id', 'matter_id'], 'time_matter_fk')->references(['tenant_id', 'id'])->on('matters');
            $table->foreign(['tenant_id', 'membership_id'], 'time_membership_fk')->references(['tenant_id', 'id'])->on('tenant_memberships');
            $table->foreign(['tenant_id', 'invoice_line_id'], 'time_line_fk')->references(['tenant_id', 'id'])->on('invoice_lines');
        });
        DB::statement('ALTER TABLE time_entries ADD CONSTRAINT time_minutes_chk CHECK (minutes > 0)');
        DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_status_chk CHECK (status IN ('draft','approved','billed','written_off'))");

        Schema::create('matter_expenses', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('matter_id');
            $table->date('expense_date');
            $table->string('category', 60);
            $table->decimal('amount', 14, 2);
            $table->boolean('is_reimbursable')->default(true);
            $table->boolean('is_pure_agent')->default(false);
            $table->pnUuid('receipt_document_id')->nullable();
            $table->pnUuid('invoice_line_id')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->foreign(['tenant_id', 'matter_id'], 'expenses_matter_fk')->references(['tenant_id', 'id'])->on('matters');
            $table->foreign(['tenant_id', 'receipt_document_id'], 'expenses_receipt_fk')->references(['tenant_id', 'id'])->on('documents');
            $table->foreign(['tenant_id', 'invoice_line_id'], 'expenses_line_fk')->references(['tenant_id', 'id'])->on('invoice_lines');
        });
        DB::statement('ALTER TABLE matter_expenses ADD CONSTRAINT expenses_amount_chk CHECK (amount >= 0)');

        Schema::create('payments', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('client_id');
            $table->decimal('amount', 14, 2);
            $table->date('payment_date');
            $table->string('method', 14);
            $table->string('reference_number', 80)->nullable();
            $table->string('status', 10)->default('recorded');
            $table->pnUuid('received_by');
            $table->string('gateway_transaction_id', 100)->nullable();
            $table->text('notes')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id']);
            $table->foreign('received_by')->references('id')->on('users');
            $table->foreign(['tenant_id', 'client_id'], 'payments_client_fk')->references(['tenant_id', 'id'])->on('clients');
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_chk CHECK (amount > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_chk CHECK (method IN ('cash','cheque','bank_transfer','upi','card','gateway'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_chk CHECK (status IN ('recorded','bounced','reversed'))");

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('tenant_id');
            $table->pnUuid('payment_id');
            $table->pnUuid('invoice_id');
            $table->decimal('amount', 14, 2);
            $table->decimal('tds_amount', 14, 2)->default(0);
            $table->datetimes(6);

            $table->unique(['payment_id', 'invoice_id']);
            $table->foreign(['tenant_id', 'payment_id'], 'allocations_payment_fk')->references(['tenant_id', 'id'])->on('payments');
            $table->foreign(['tenant_id', 'invoice_id'], 'allocations_invoice_fk')->references(['tenant_id', 'id'])->on('invoices');
        });
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT allocations_amounts_chk CHECK (amount >= 0 AND tds_amount >= 0 AND amount + tds_amount > 0)');
    }

    public function down(): void
    {
        foreach (['payment_allocations', 'payments', 'matter_expenses', 'time_entries', 'invoice_lines', 'invoices', 'invoice_series', 'retainer_work_items', 'fee_schedule_items', 'fee_arrangements', 'retainer_plans', 'tax_rules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
