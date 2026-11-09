<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single platform tenant, which holds platform role assignments. Safe to re-run.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('tenants')->where('type', 'platform')->exists()) {
            return;
        }

        DB::table('tenants')->insert([
            'id' => (string) Str::uuid7(),
            'type' => 'platform',
            'name' => 'PocketNyaya Platform',
            'slug' => (string) config('pocketnyaya.platform_tenant_slug'),
            'gst_registration_type' => 'unregistered',
            'state_code' => '33',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
