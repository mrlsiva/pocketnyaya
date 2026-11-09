<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Credit notes (owner decision 2026-11-09). A credit note reduces the original
 * invoice's balance. The invoice CHECKs only let the balance fall through
 * allocations, so a credit note is applied as a payment with method credit_note.
 * Provisional until a CA reviews the credit note treatment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_method_chk');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_chk CHECK (method IN ('cash','cheque','bank_transfer','upi','card','gateway','credit_note'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_method_chk');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_chk CHECK (method IN ('cash','cheque','bank_transfer','upi','card','gateway'))");
    }
};
