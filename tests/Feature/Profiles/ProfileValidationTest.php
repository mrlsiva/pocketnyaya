<?php

namespace Tests\Feature\Profiles;

use App\Http\Requests\Profile\StoreAdvocateProfileRequest;
use App\Http\Requests\Profile\StoreCustomerProfileRequest;
use App\Http\Requests\Profile\StoreMediatorProfileRequest;
use App\Models\AdvocateProfile;
use App\Models\BarCouncil;
use App\Models\City;
use App\Models\Court;
use App\Models\Language;
use App\Models\MediatorProfile;
use App\Models\PracticeArea;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;
use Tests\TestCase;

/**
 * Tests the validation rules directly. No routes exist yet: login and the
 * profile screens belong to later approved steps. All data is synthetic.
 */
class ProfileValidationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @param  class-string<FormRequest>  $class
     * @param  array<string, mixed>  $data
     */
    private function validate(string $class, array $data): ValidatorInstance
    {
        $request = $class::create('/', 'POST', $data);

        /** @var ValidatorInstance $validator */
        $validator = Validator::make($data, $request->rules());

        return $validator;
    }

    /** @return array<string, mixed> */
    private function advocate(array $overrides = []): array
    {
        return $overrides + [
            'bar_council_id' => BarCouncil::factory()->create()->id,
            'enrollment_number' => 'TEST/100/2018',
            'enrollment_date' => '2018-03-01',
            'city_id' => City::factory()->create()->id,
        ];
    }

    public function test_requests_require_a_signed_in_user(): void
    {
        foreach ([StoreAdvocateProfileRequest::class, StoreCustomerProfileRequest::class, StoreMediatorProfileRequest::class] as $class) {
            $request = $class::create('/', 'POST');
            $this->assertFalse($request->authorize(), $class);

            $request->setUserResolver(fn () => User::factory()->make());
            $this->assertTrue($request->authorize(), $class);
        }
    }

    public function test_valid_advocate_data_passes_with_all_optional_lists(): void
    {
        $validator = $this->validate(StoreAdvocateProfileRequest::class, $this->advocate([
            'bio' => 'Synthetic biography.',
            'qualifications' => [['degree' => 'LL.B.', 'institution' => 'Test College', 'year_of_passing' => 2017]],
            'practice_area_ids' => [PracticeArea::factory()->create()->id],
            'language_ids' => [Language::factory()->create()->id],
            'court_ids' => [Court::factory()->create()->id],
        ]));

        $this->assertTrue($validator->passes(), (string) $validator->errors());
    }

    public function test_advocate_workflow_and_owner_fields_never_reach_validated_data(): void
    {
        $validator = $this->validate(StoreAdvocateProfileRequest::class, $this->advocate([
            'user_id' => 'someone-else', 'verification_status' => 'verified', 'verified_by' => 'x',
            'is_directory_listed' => true, 'bio_screening_status' => 'passed', 'rating' => 5,
        ]));

        $this->assertTrue($validator->passes());
        $validated = $validator->validated();
        foreach (['user_id', 'verification_status', 'verified_by', 'is_directory_listed', 'bio_screening_status', 'rating'] as $field) {
            $this->assertArrayNotHasKey($field, $validated);
        }
    }

    public function test_advocate_required_fields_and_references(): void
    {
        $errors = $this->validate(StoreAdvocateProfileRequest::class, [])->errors();
        foreach (['bar_council_id', 'enrollment_number', 'enrollment_date', 'city_id'] as $field) {
            $this->assertTrue($errors->has($field), $field);
        }

        $errors = $this->validate(StoreAdvocateProfileRequest::class, $this->advocate(['bar_council_id' => 999999999, 'city_id' => 999999999]))->errors();
        $this->assertTrue($errors->has('bar_council_id'));
        $this->assertTrue($errors->has('city_id'));
    }

    public function test_advocate_enrollment_rules(): void
    {
        $errors = fn (array $change) => $this->validate(StoreAdvocateProfileRequest::class, $this->advocate($change))->errors();

        $this->assertTrue($errors(['enrollment_date' => now()->addDay()->toDateString()])->has('enrollment_date'));
        $this->assertTrue($errors(['enrollment_date' => 'not-a-date'])->has('enrollment_date'));
        $this->assertTrue($errors(['enrollment_number' => str_repeat('1', 41)])->has('enrollment_number'));
        $this->assertTrue($errors(['enrollment_number' => '<script>'])->has('enrollment_number'));
    }

    public function test_enrollment_number_already_used_at_that_council_is_rejected_even_if_soft_deleted(): void
    {
        $existing = AdvocateProfile::factory()->create(['enrollment_number' => 'TEST/7/2011']);
        $data = $this->advocate(['bar_council_id' => $existing->bar_council_id, 'enrollment_number' => 'TEST/7/2011']);

        $this->assertTrue($this->validate(StoreAdvocateProfileRequest::class, $data)->errors()->has('enrollment_number'));

        $existing->delete();
        $this->assertTrue($this->validate(StoreAdvocateProfileRequest::class, $data)->errors()->has('enrollment_number'));

        $otherCouncil = $this->advocate(['enrollment_number' => 'TEST/7/2011']);
        $this->assertTrue($this->validate(StoreAdvocateProfileRequest::class, $otherCouncil)->passes());
    }

    public function test_advocate_nested_lists_are_validated(): void
    {
        $errors = $this->validate(StoreAdvocateProfileRequest::class, $this->advocate([
            'qualifications' => [['degree' => '', 'institution' => 'College', 'year_of_passing' => now()->year + 1]],
            'language_ids' => [999999999],
            'practice_area_ids' => [1, 1],
        ]))->errors();

        $this->assertTrue($errors->has('qualifications.0.degree'));
        $this->assertTrue($errors->has('qualifications.0.year_of_passing'));
        $this->assertTrue($errors->has('language_ids.0'));
        $this->assertTrue($errors->has('practice_area_ids.0'));
    }

    public function test_customer_rules(): void
    {
        $errors = fn (array $data) => $this->validate(StoreCustomerProfileRequest::class, $data)->errors();

        $this->assertTrue($errors([])->has('is_business'));
        $this->assertTrue($errors(['is_business' => true])->has('business_name'));
        $this->assertFalse($errors(['is_business' => false])->has('business_name'));
        $this->assertTrue($errors(['is_business' => false, 'gstin' => 'BADGSTIN'])->has('gstin'));
        $this->assertTrue($errors(['is_business' => false, 'city_id' => 999999999])->has('city_id'));
        $this->assertTrue($this->validate(StoreCustomerProfileRequest::class, [
            'is_business' => true, 'business_name' => 'Test Traders', 'gstin' => '33AAAAA0000A1Z5',
        ])->passes());
    }

    public function test_customer_owner_field_never_reaches_validated_data(): void
    {
        $validator = $this->validate(StoreCustomerProfileRequest::class, ['is_business' => false, 'user_id' => 'someone-else']);

        $this->assertArrayNotHasKey('user_id', $validator->validated());
    }

    public function test_mediator_rules(): void
    {
        $base = ['accreditation_body' => 'Test Body', 'accreditation_number' => 'M-100'];
        $errors = fn (array $change) => $this->validate(StoreMediatorProfileRequest::class, $change + $base)->errors();

        $this->assertTrue($this->validate(StoreMediatorProfileRequest::class, [])->errors()->has('accreditation_body'));
        $this->assertTrue($this->validate(StoreMediatorProfileRequest::class, [])->errors()->has('accreditation_number'));
        $this->assertTrue($errors(['accreditation_valid_until' => now()->subDay()->toDateString()])->has('accreditation_valid_until'));
        $this->assertTrue($errors(['categories' => ['criminal']])->has('categories.0'));
        $this->assertFalse($errors(['categories' => ['ecommerce', 'other']])->any());

        MediatorProfile::factory()->create($base);
        $this->assertTrue($errors([])->has('accreditation_number'));
        $this->assertFalse($errors(['accreditation_body' => 'Another Body'])->any());
    }

    public function test_mediator_workflow_and_owner_fields_never_reach_validated_data(): void
    {
        $validator = $this->validate(StoreMediatorProfileRequest::class, [
            'accreditation_body' => 'Test Body', 'accreditation_number' => 'M-200',
            'user_id' => 'someone-else', 'verification_status' => 'verified', 'is_available' => true,
        ]);

        $validated = $validator->validated();
        foreach (['user_id', 'verification_status', 'is_available'] as $field) {
            $this->assertArrayNotHasKey($field, $validated);
        }
    }
}
