<?php

namespace App\Http\Requests\Profile;

use App\Models\MediatorCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accepts only the applicant's own details. Owner, verification and availability
 * fields are deliberately absent, so they never reach validated().
 */
class StoreMediatorProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'accreditation_body' => ['required', 'string', 'max:150'],
            'accreditation_number' => [
                'required', 'string', 'max:60',
                Rule::unique('mediator_profiles', 'accreditation_number')->where('accreditation_body', $this->input('accreditation_body')),
            ],
            'accreditation_valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'fee_terms' => ['nullable', 'string', 'max:2000'],
            'language_ids' => ['nullable', 'array'],
            'language_ids.*' => ['integer', 'distinct', 'exists:languages,id'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'distinct', Rule::in(MediatorCategory::CODES)],
        ];
    }
}
