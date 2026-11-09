<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([ReferenceDataSeeder::class, RoleSeeder::class, PlatformSeeder::class, ScreeningRuleSeeder::class, PrivacySeeder::class]);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
