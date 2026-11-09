<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accepts only the applicant's own details. Owner, verification, listing and
 * screening fields are deliberately absent, so they never reach validated().
 */
class StoreAdvocateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bar_council_id' => ['required', 'integer', 'exists:bar_councils,id'],
            'enrollment_number' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9\/\-. ]*$/',
                // Includes soft-deleted rows: enrollment numbers aren't reused.
                Rule::unique('advocate_profiles', 'enrollment_number')->where('bar_council_id', $this->input('bar_council_id')),
            ],
            'enrollment_date' => ['required', 'date', 'before_or_equal:today'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'office_address' => ['nullable', 'string', 'max:500'],
            'qualifications' => ['nullable', 'array', 'max:20'],
            'qualifications.*.degree' => ['required', 'string', 'max:120'],
            'qualifications.*.institution' => ['required', 'string', 'max:200'],
            'qualifications.*.year_of_passing' => ['required', 'integer', 'min:1900', 'max:'.now()->year],
            'practice_area_ids' => ['nullable', 'array'],
            'practice_area_ids.*' => ['integer', 'distinct', 'exists:practice_areas,id'],
            'language_ids' => ['nullable', 'array'],
            'language_ids.*' => ['integer', 'distinct', 'exists:languages,id'],
            'court_ids' => ['nullable', 'array'],
            'court_ids.*' => ['integer', 'distinct', 'exists:courts,id'],
        ];
    }
}
