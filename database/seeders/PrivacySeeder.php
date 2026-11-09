<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starting consent purposes and disclaimers. The wording is a provisional draft and
 * must be reviewed by counsel before launch. Safe to re-run.
 */
class PrivacySeeder extends Seeder
{
    public function run(): void
    {
        $today = '2026-01-01';

        foreach ([
            ['account', 'Running your account', 'We use your name and contact details to create your account, keep it secure and contact you about it.', true],
            ['document_processing', 'Storing your documents', 'We store the documents you upload, encrypted, so you and the people you choose can use them.', true],
            ['ai_processing', 'AI assistance', 'Optional. Lets AI features process text you give them. These features are not switched on yet.', false],
            ['ml_training', 'Improving our models', 'Optional. Lets masked content help train our models. Off unless you turn it on.', false],
            ['notifications_marketing', 'Product news', 'Optional. Lets us send you news about the service.', false],
        ] as [$code, $name, $description, $mandatory]) {
            if (DB::table('consent_purposes')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('consent_purposes')->insert([
                'id' => (string) Str::uuid7(), 'code' => $code, 'version' => 1, 'name' => $name, 'description' => $description,
                'is_mandatory' => $mandatory, 'effective_from' => $today, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            ['directory_entry', 'About this directory', 'This directory provides information about advocates at the request of users. It is not an advertisement or a solicitation of work, and it doesn\'t rank or recommend anyone.'],
            ['forum', 'About community answers', 'Answers are general information, not legal advice, and they don\'t create an advocate-client relationship.'],
            ['consultation', 'About consultations', 'Sending a request doesn\'t create an advocate-client relationship until the advocate accepts it. Fees are agreed directly with the advocate.'],
            ['odr', 'About online dispute resolution', 'Taking part is voluntary. A settlement agreement produced here is unsigned. It isn\'t a court order or decree, and nothing here guarantees that it can be enforced. Please get independent legal advice.'],
            ['ai_output', 'About AI output', 'AI output can be wrong and isn\'t legal advice. A qualified advocate must check it before use.'],
        ] as [$code, $title, $body]) {
            if (DB::table('disclaimers')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('disclaimers')->insert([
                'id' => (string) Str::uuid7(), 'code' => $code, 'version' => 1, 'title' => $title, 'body' => $body,
                'effective_from' => $today, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
