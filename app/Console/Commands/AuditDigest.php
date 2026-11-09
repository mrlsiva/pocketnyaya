<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Writes the daily anchor hash per tenant, so later tampering with audit rows shows. */
class AuditDigest extends Command
{
    protected $signature = 'pocketnyaya:audit-digest {date? : The day to anchor, YYYY-MM-DD. Defaults to yesterday.}';

    protected $description = 'Anchors one day of audit rows with a hash for each tenant';

    public function handle(): int
    {
        $date = (string) ($this->argument('date') ?? now()->subDay()->toDateString());
        $range = [$date.' 00:00:00', $date.' 23:59:59.999999'];
        $count = 0;

        foreach (DB::table('audit_logs')->whereBetween('created_at', $range)->distinct()->pluck('tenant_id') as $tenant) {
            $rows = DB::table('audit_logs')->whereBetween('created_at', $range)->where('tenant_id', $tenant)->orderBy('id')->get(['id', 'row_hash']);
            $root = hash('sha256', $rows->pluck('row_hash')->implode(''));

            DB::table('audit_digests')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'digest_date' => $date,
                'first_id' => $rows->first()->id, 'last_id' => $rows->last()->id, 'root_hash' => $root, 'created_at' => now(),
            ]);
            $count++;
        }

        $this->info("Anchored {$count} scope(s) for {$date}.");

        return self::SUCCESS;
    }
}
