<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerProfileRequest extends FormRequest
{
    /** Format check only. It doesn't confirm that a GSTIN is registered. */
    public const GSTIN_FORMAT = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:150'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'preferred_language_id' => ['nullable', 'integer', 'exists:languages,id'],
            'is_business' => ['required', 'boolean'],
            'business_name' => ['nullable', 'required_if_accepted:is_business', 'string', 'max:200'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:'.self::GSTIN_FORMAT],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
