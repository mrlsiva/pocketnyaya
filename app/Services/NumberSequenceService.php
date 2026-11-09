<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * C16: a locked counter row, so a rolled-back transaction never consumes a number.
 */
class NumberSequenceService
{
    public function next(?string $tenantId, string $key, string $period): int
    {
        return DB::transaction(function () use ($tenantId, $key, $period) {
            DB::table('number_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'sequence_key' => $key,
                'period' => $period, 'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $row = DB::table('number_sequences')
                ->where('tenant_key', $tenantId ?? 'PLATFORM')->where('sequence_key', $key)->where('period', $period)
                ->lockForUpdate()->first();

            DB::table('number_sequences')->where('id', $row->id)->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return (int) $row->next_value;
        });
    }
}
