<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Public reference data: GST state codes and common lists. Safe to re-run.
 */
class ReferenceDataSeeder extends Seeder
{
    private const STATES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh',
        '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
        '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
        '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
        '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
        '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh',
    ];

    private const LANGUAGES = [
        'en' => ['English', 'English'], 'ta' => ['Tamil', 'தமிழ்'], 'hi' => ['Hindi', 'हिन्दी'], 'te' => ['Telugu', 'తెలుగు'],
        'kn' => ['Kannada', 'ಕನ್ನಡ'], 'ml' => ['Malayalam', 'മലയാളം'], 'bn' => ['Bengali', 'বাংলা'], 'mr' => ['Marathi', 'मराठी'],
        'gu' => ['Gujarati', 'ગુજરાતી'], 'pa' => ['Punjabi', 'ਪੰਜਾਬੀ'], 'ur' => ['Urdu', 'اردو'],
    ];

    private const PRACTICE_AREAS = [
        'civil' => 'Civil Law', 'criminal' => 'Criminal Law', 'family' => 'Family Law', 'property' => 'Property Law',
        'corporate' => 'Corporate Law', 'consumer' => 'Consumer Protection', 'labour' => 'Labour and Employment',
        'tax' => 'Tax Law', 'intellectual-property' => 'Intellectual Property', 'constitutional' => 'Constitutional Law',
        'cyber' => 'Cyber Law', 'banking' => 'Banking and Finance',
    ];

    public function run(): void
    {
        foreach (self::STATES as $code => $name) {
            DB::table('states')->updateOrInsert(['code' => $code], ['name' => $name, 'updated_at' => now(), 'created_at' => now()]);
        }

        foreach (self::LANGUAGES as $code => [$name, $native]) {
            DB::table('languages')->updateOrInsert(['code' => $code], ['name' => $name, 'native_name' => $native, 'updated_at' => now(), 'created_at' => now()]);
        }

        foreach (self::PRACTICE_AREAS as $slug => $name) {
            DB::table('practice_areas')->updateOrInsert(['slug' => $slug], ['name' => $name, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }

        $tamilNadu = (int) DB::table('states')->where('code', '33')->value('id');
        DB::table('cities')->updateOrInsert(['state_id' => $tamilNadu, 'name' => 'Chennai'], ['is_pilot' => true, 'updated_at' => now(), 'created_at' => now()]);
        $chennai = (int) DB::table('cities')->where(['state_id' => $tamilNadu, 'name' => 'Chennai'])->value('id');

        DB::table('bar_councils')->updateOrInsert(['code' => 'BCTN'], ['state_id' => $tamilNadu, 'name' => 'Bar Council of Tamil Nadu and Puducherry', 'updated_at' => now(), 'created_at' => now()]);

        DB::table('courts')->updateOrInsert(
            ['name' => 'Madras High Court'],
            ['court_type' => 'high', 'state_id' => $tamilNadu, 'city_id' => $chennai, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]
        );
        DB::table('courts')->updateOrInsert(
            ['name' => 'City Civil Court, Chennai'],
            ['court_type' => 'district', 'state_id' => $tamilNadu, 'city_id' => $chennai, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
