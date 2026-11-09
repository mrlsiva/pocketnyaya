<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A provisional starting set for BCI Rule 36 screening, in English only. Staff can
 * change these on screen, and counsel should review them before launch.
 */
class ScreeningRuleSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('screening_rules')->exists()) {
            return;
        }

        $rules = [
            ['contact_pattern', 'contact_info', '(?<!\d)(?:\+?91[\s-]?)?[6-9]\d{9}(?!\d)', 'block'],
            ['contact_pattern', 'contact_info', '[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}', 'block'],
            ['contact_pattern', 'contact_info', '(?:https?://|www\.)\S+', 'flag'],
            ['keyword', 'laudatory', 'best lawyer', 'flag'],
            ['keyword', 'laudatory', 'top advocate', 'flag'],
            ['keyword', 'laudatory', 'number one lawyer', 'flag'],
            ['keyword', 'laudatory', 'guaranteed win', 'block'],
            ['keyword', 'laudatory', '100% success', 'block'],
            ['keyword', 'solicitation', 'hire me', 'block'],
            ['keyword', 'solicitation', 'contact me', 'flag'],
            ['keyword', 'solicitation', 'call my office', 'flag'],
            ['regex', 'fee_quote', '(?:₹|rs\.?|inr)\s?\d[\d,]*.{0,20}\b(?:fee|fees|charge|charges)\b', 'flag'],
            ['regex', 'fee_quote', '\b(?:my|our)\s+(?:fee|fees|charges)\b', 'flag'],
        ];

        foreach ($rules as [$type, $category, $pattern, $severity]) {
            DB::table('screening_rules')->insert([
                'id' => (string) Str::uuid7(), 'rule_type' => $type, 'category' => $category, 'pattern' => $pattern,
                'severity' => $severity, 'applies_to' => 'all', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
