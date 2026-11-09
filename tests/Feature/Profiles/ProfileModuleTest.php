<?php

namespace Tests\Feature\Profiles;

use App\Models\AdvocateProfile;
use App\Models\AdvocateQualification;
use App\Models\BarCouncil;
use App\Models\City;
use App\Models\Court;
use App\Models\CustomerProfile;
use App\Models\Language;
use App\Models\MediatorProfile;
use App\Models\PracticeArea;
use App\Models\State;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Runs against the real schema inside a transaction that is rolled back.
 * Never add RefreshDatabase here: it would drop the development tables.
 * All data is synthetic.
 */
class ProfileModuleTest extends TestCase
{
    use DatabaseTransactions;

    private const MODULE_TABLES = [
        'advocate_profiles', 'advocate_qualifications', 'advocate_practice_areas', 'advocate_languages',
        'advocate_courts', 'verification_requests', 'customer_profiles', 'mediator_profiles',
        'mediator_languages', 'mediator_categories',
    ];

    private function assertRejected(callable $write, string $expected): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());

            return;
        }
        $this->fail("Expected the database to reject this write with: {$expected}");
    }

    private function firm(): string
    {
        $id = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $id, 'type' => 'firm', 'name' => 'Test Firm', 'slug' => 'firm-'.Str::lower(Str::random(12)),
            'gst_registration_type' => 'unregistered', 'state_code' => State::factory()->create()->code,
        ]);

        return $id;
    }

    private function join(string $tenant, User $user): void
    {
        DB::table('tenant_memberships')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'user_id' => $user->id, 'status' => 'active',
        ]);
    }

    // Ownership

    public function test_each_user_has_at_most_one_profile_of_each_type(): void
    {
        $user = User::factory()->create();
        AdvocateProfile::factory()->create(['user_id' => $user->id]);
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        MediatorProfile::factory()->create(['user_id' => $user->id]);

        $this->assertRejected(fn () => AdvocateProfile::factory()->create(['user_id' => $user->id]), 'Duplicate entry');
        $this->assertRejected(fn () => CustomerProfile::factory()->create(['user_id' => $user->id]), 'Duplicate entry');
        $this->assertRejected(fn () => MediatorProfile::factory()->create(['user_id' => $user->id]), 'Duplicate entry');
    }

    public function test_one_person_can_hold_all_three_profile_types(): void
    {
        $user = User::factory()->create();
        $advocate = AdvocateProfile::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $mediator = MediatorProfile::factory()->create(['user_id' => $user->id]);

        $user = $user->fresh();
        $this->assertTrue($user->advocateProfile->is($advocate));
        $this->assertTrue($user->customerProfile->is($customer));
        $this->assertTrue($user->mediatorProfile->is($mediator));
    }

    public function test_is_owned_by_only_matches_the_owner(): void
    {
        $profile = AdvocateProfile::factory()->create();
        $other = User::factory()->create();

        $this->assertTrue($profile->isOwnedBy($profile->user));
        $this->assertFalse($profile->isOwnedBy($other));
    }

    public function test_owner_and_workflow_fields_cannot_be_mass_assigned(): void
    {
        $advocate = new AdvocateProfile([
            'user_id' => (string) Str::uuid(), 'verification_status' => 'verified', 'verified_at' => now(),
            'verified_by' => (string) Str::uuid(), 'is_directory_listed' => true, 'bio_screening_status' => 'passed',
            'erased_at' => now(),
        ]);
        $this->assertSame([], $advocate->getAttributes());

        $mediator = new MediatorProfile(['user_id' => (string) Str::uuid(), 'verification_status' => 'verified', 'is_available' => true]);
        $this->assertSame([], $mediator->getAttributes());

        $customer = new CustomerProfile(['user_id' => (string) Str::uuid()]);
        $this->assertSame([], $customer->getAttributes());

        $request = new VerificationRequest(['status' => 'approved', 'reviewed_by' => (string) Str::uuid(), 'decision_notes' => 'x']);
        $this->assertSame([], $request->getAttributes());
    }

    public function test_profile_is_created_through_its_owner_relation(): void
    {
        $user = User::factory()->create();
        $profile = $user->advocateProfile()->create([
            'bar_council_id' => BarCouncil::factory()->create()->id,
            'enrollment_number' => 'TEST/1/2020',
            'enrollment_date' => '2020-01-15',
            'city_id' => City::factory()->create()->id,
        ]);

        $fresh = $profile->fresh();
        $this->assertSame($user->id, $fresh->user_id);
        $this->assertSame('draft', $fresh->verification_status);
        $this->assertFalse($fresh->is_directory_listed);
        $this->assertSame('pending', $fresh->bio_screening_status);
        $this->assertSame('7', $fresh->id[14]);
    }

    public function test_customer_profile_is_created_lazily_and_only_once(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->customerProfile);

        $first = $user->ensureCustomerProfile();
        $second = $user->ensureCustomerProfile();

        $this->assertTrue($first->is($second));
        $this->assertSame(1, CustomerProfile::query()->where('user_id', $user->id)->count());
    }

    public function test_a_profile_must_point_at_a_real_user(): void
    {
        $this->assertRejected(fn () => DB::table('customer_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => (string) Str::uuid(),
        ]), 'foreign key constraint fails');
    }

    // Tenant boundaries

    public function test_profile_tables_are_platform_level_with_no_tenant_column(): void
    {
        foreach (self::MODULE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertFalse(Schema::hasColumn($table, 'tenant_id'), $table);
        }
    }

    public function test_one_profile_stays_with_the_person_across_firms_and_memberships(): void
    {
        $user = User::factory()->create();
        $profile = AdvocateProfile::factory()->create(['user_id' => $user->id]);
        $firmA = $this->firm();
        $firmB = $this->firm();
        $this->join($firmA, $user);
        $this->join($firmB, $user);

        setPermissionsTeamId($firmA);
        $this->assertTrue($user->fresh()->advocateProfile->is($profile));
        setPermissionsTeamId($firmB);
        $this->assertTrue($user->fresh()->advocateProfile->is($profile));

        DB::table('tenant_memberships')->where(['tenant_id' => $firmA, 'user_id' => $user->id])->delete();
        $this->assertNotNull(AdvocateProfile::query()->find($profile->id));
    }

    public function test_a_firm_colleague_does_not_own_another_members_profile(): void
    {
        $firm = $this->firm();
        $owner = User::factory()->create();
        $colleague = User::factory()->create();
        $this->join($firm, $owner);
        $this->join($firm, $colleague);
        $profile = CustomerProfile::factory()->create(['user_id' => $owner->id]);

        $this->assertFalse($profile->isOwnedBy($colleague));
        $this->assertNull($colleague->fresh()->customerProfile);
    }

    public function test_holding_a_profile_grants_no_role_or_permission(): void
    {
        $user = User::factory()->create();
        AdvocateProfile::factory()->listed()->create(['user_id' => $user->id]);
        MediatorProfile::factory()->create(['user_id' => $user->id, 'verification_status' => 'verified']);
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $user->id)->count());
        $this->assertSame(0, DB::table('model_has_permissions')->where('model_id', $user->id)->count());
    }

    // BCI Rule 36 data boundary

    public function test_advocate_profile_has_no_commercial_ranking_columns(): void
    {
        $columns = Schema::getColumnListing('advocate_profiles');
        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression('/rating|review|score|tier|featured|sponsor|rank|badge/i', $column);
        }
        $this->assertNotContains('years_at_bar', $columns);
    }

    public function test_public_listing_requires_verified_and_listed(): void
    {
        $listed = AdvocateProfile::factory()->listed()->create();
        AdvocateProfile::factory()->verified()->create();
        AdvocateProfile::factory()->create(['is_directory_listed' => true]);
        AdvocateProfile::factory()->create(['verification_status' => 'suspended', 'is_directory_listed' => true]);
        AdvocateProfile::factory()->listed()->create()->delete();

        $ids = AdvocateProfile::query()->publiclyListed()->pluck('id')->all();
        $this->assertSame([$listed->id], $ids);
    }

    public function test_years_at_bar_is_calculated_from_enrollment_date(): void
    {
        $this->travelTo('2026-06-14 12:00:00');
        $profile = AdvocateProfile::factory()->create(['enrollment_date' => '2015-06-15']);

        $this->assertSame(10, $profile->fresh()->years_at_bar);
        $this->travelTo('2026-06-15 12:00:00');
        $this->assertSame(11, $profile->fresh()->years_at_bar);
    }

    // Identifiers

    public function test_enrollment_number_is_unique_per_council_and_not_reused_after_soft_delete(): void
    {
        $council = BarCouncil::factory()->create();
        $make = fn (array $extra = []) => AdvocateProfile::factory()->create(['bar_council_id' => $council->id, 'enrollment_number' => 'TEST/9/2010'] + $extra);

        $first = $make();
        $this->assertRejected($make, 'advocate_enrollment_uq');

        $first->delete();
        $this->assertRejected($make, 'advocate_enrollment_uq');

        $this->assertNotNull(AdvocateProfile::factory()->create(['enrollment_number' => 'TEST/9/2010'])->id);
    }

    public function test_enrollment_number_may_only_be_empty_after_erasure_and_is_then_released(): void
    {
        $council = BarCouncil::factory()->create();
        $this->assertRejected(
            fn () => AdvocateProfile::factory()->create(['bar_council_id' => $council->id, 'enrollment_number' => null]),
            'advocate_enrollment_chk'
        );

        $profile = AdvocateProfile::factory()->create(['bar_council_id' => $council->id, 'enrollment_number' => 'TEST/5/2012']);
        DB::table('advocate_profiles')->where('id', $profile->id)->update(['enrollment_number' => null, 'erased_at' => now()]);
        AdvocateProfile::factory()->create(['bar_council_id' => $council->id, 'enrollment_number' => null, 'erased_at' => now()]);

        $again = AdvocateProfile::factory()->create(['bar_council_id' => $council->id, 'enrollment_number' => 'TEST/5/2012']);
        $this->assertSame('TEST/5/2012', $again->enrollment_number);
    }

    public function test_accreditation_number_follows_the_same_erasure_rule(): void
    {
        $make = fn (array $extra = []) => MediatorProfile::factory()->create($extra + ['accreditation_body' => 'Body A', 'accreditation_number' => 'M-1']);

        $profile = $make();
        $this->assertRejected($make, 'mediator_accreditation_uq');
        $this->assertRejected(fn () => $make(['accreditation_number' => null]), 'mediator_accreditation_chk');

        DB::table('mediator_profiles')->where('id', $profile->id)->update(['accreditation_number' => null, 'erased_at' => now()]);
        $this->assertNotNull($make()->id);
    }

    // Database value checks

    public function test_invalid_status_and_category_values_are_rejected(): void
    {
        $this->assertRejected(fn () => AdvocateProfile::factory()->create(['verification_status' => 'gold']), 'advocate_verification_chk');
        $this->assertRejected(fn () => AdvocateProfile::factory()->create(['bio_screening_status' => 'maybe']), 'advocate_screening_chk');
        $this->assertRejected(fn () => MediatorProfile::factory()->create(['verification_status' => 'draft']), 'mediator_verification_chk');
        $this->assertRejected(fn () => VerificationRequest::factory()->create(['status' => 'unknown']), 'verification_requests_status_chk');
        $this->assertRejected(fn () => MediatorProfile::factory()->create()->categories()->create(['category' => 'criminal']), 'mediator_category_chk');
    }

    public function test_invalid_references_are_rejected(): void
    {
        $this->assertRejected(fn () => AdvocateProfile::factory()->create(['bar_council_id' => 999999999]), 'foreign key constraint fails');
        $this->assertRejected(fn () => AdvocateProfile::factory()->create(['city_id' => 999999999]), 'foreign key constraint fails');
        $this->assertRejected(fn () => AdvocateProfile::factory()->create(['verified_by' => (string) Str::uuid()]), 'foreign key constraint fails');
        $this->assertRejected(fn () => VerificationRequest::factory()->create(['advocate_profile_id' => (string) Str::uuid()]), 'foreign key constraint fails');
        $this->assertRejected(fn () => CustomerProfile::factory()->create(['preferred_language_id' => 999999999]), 'foreign key constraint fails');
    }

    public function test_reference_data_in_use_cannot_be_removed(): void
    {
        $profile = AdvocateProfile::factory()->create();
        $area = PracticeArea::factory()->create();
        $profile->practiceAreas()->attach($area);

        $this->assertRejected(fn () => $area->delete(), 'foreign key constraint fails');
        $this->assertRejected(fn () => $profile->barCouncil->delete(), 'foreign key constraint fails');
    }

    public function test_customer_address_is_encrypted_at_rest_and_hidden_from_output(): void
    {
        $profile = CustomerProfile::factory()->create(['address' => 'Synthetic Address 1']);

        $raw = DB::table('customer_profiles')->where('id', $profile->id)->value('address');
        $this->assertStringNotContainsString('Synthetic', (string) $raw);
        $this->assertSame('Synthetic Address 1', $profile->fresh()->address);
        $this->assertArrayNotHasKey('address', $profile->fresh()->toArray());
    }

    // Relationships

    public function test_advocate_relationships_load_and_pivots_reject_duplicates(): void
    {
        $profile = AdvocateProfile::factory()->create();
        $area = PracticeArea::factory()->create();
        $language = Language::factory()->create();
        $court = Court::factory()->create();
        $profile->practiceAreas()->attach($area);
        $profile->languages()->attach($language);
        $profile->courts()->attach($court);
        $qualification = AdvocateQualification::factory()->create(['advocate_profile_id' => $profile->id]);

        $fresh = $profile->fresh();
        $this->assertTrue($fresh->practiceAreas->first()->is($area));
        $this->assertTrue($fresh->languages->first()->is($language));
        $this->assertTrue($fresh->courts->first()->is($court));
        $this->assertTrue($fresh->qualifications->first()->is($qualification));
        $this->assertNotNull($fresh->barCouncil->state);
        $this->assertNotNull($fresh->city->state);

        $this->assertRejected(fn () => $profile->practiceAreas()->attach($area), 'Duplicate entry');
    }

    public function test_verification_request_links_profile_assignee_and_reviewer(): void
    {
        $profile = AdvocateProfile::factory()->create();
        $officer = User::factory()->create();
        $request = VerificationRequest::factory()->create([
            'advocate_profile_id' => $profile->id, 'assigned_to' => $officer->id, 'reviewed_by' => $officer->id,
        ]);

        $fresh = $request->fresh();
        $this->assertTrue($fresh->advocateProfile->is($profile));
        $this->assertTrue($fresh->assignee->is($officer));
        $this->assertTrue($fresh->reviewer->is($officer));
        $this->assertSame('submitted', $fresh->status);
        $this->assertSame(['enrollment_certificate' => false], $fresh->checklist);
        $this->assertSame(1, $profile->verificationRequests()->count());
    }

    public function test_a_profile_can_have_several_verification_requests_over_time(): void
    {
        $profile = AdvocateProfile::factory()->create();
        VerificationRequest::factory()->create(['advocate_profile_id' => $profile->id, 'status' => 'rejected']);
        VerificationRequest::factory()->create(['advocate_profile_id' => $profile->id]);

        $this->assertSame(2, $profile->verificationRequests()->count());
    }

    public function test_mediator_languages_and_categories(): void
    {
        $profile = MediatorProfile::factory()->create();
        $language = Language::factory()->create();
        $profile->languages()->attach($language);
        $profile->categories()->create(['category' => 'landlord_tenant']);
        $profile->categories()->create(['category' => 'ecommerce']);

        $fresh = $profile->fresh();
        $this->assertTrue($fresh->languages->first()->is($language));
        $this->assertEqualsCanonicalizing(['landlord_tenant', 'ecommerce'], $fresh->categories->pluck('category')->all());
        $this->assertFalse($fresh->is_available);
        $this->assertSame('pending', $fresh->verification_status);

        $this->assertRejected(fn () => $profile->categories()->create(['category' => 'ecommerce']), 'Duplicate entry');
    }

    public function test_removing_a_category_only_affects_that_mediator(): void
    {
        $first = MediatorProfile::factory()->create();
        $second = MediatorProfile::factory()->create();
        $first->categories()->create(['category' => 'service']);
        $second->categories()->create(['category' => 'service']);

        $first->categories()->first()->delete();

        $this->assertSame(0, $first->categories()->count());
        $this->assertSame(1, $second->categories()->count());
    }

    public function test_soft_deleting_a_profile_hides_it_and_keeps_its_links(): void
    {
        $profile = AdvocateProfile::factory()->create();
        $profile->languages()->attach(Language::factory()->create());
        $profile->delete();

        $this->assertNull(AdvocateProfile::query()->find($profile->id));
        $this->assertNotNull(AdvocateProfile::withTrashed()->find($profile->id));
        $this->assertSame(1, DB::table('advocate_languages')->where('advocate_profile_id', $profile->id)->count());
    }

    public function test_a_user_in_use_by_a_profile_cannot_be_hard_deleted(): void
    {
        $profile = CustomerProfile::factory()->create();

        $this->assertRejected(fn () => $profile->user->forceDelete(), 'foreign key constraint fails');
    }
}
