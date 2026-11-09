<?php

namespace App\Http\Requests\Practice;

use App\Http\Requests\Profile\StoreCustomerProfileRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_type' => ['required', Rule::in(['individual', 'business', 'government'])],
            'name' => ['required', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{7,20}$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'state_code' => ['required', 'exists:states,code'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:'.StoreCustomerProfileRequest::GSTIN_FORMAT],
            'pan' => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'is_business_entity' => ['sometimes', 'boolean'],
            'turnover_category' => ['nullable', Rule::in(['unknown', 'below_threshold', 'above_threshold'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }
}
