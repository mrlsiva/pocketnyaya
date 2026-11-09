<?php

namespace App\Http\Requests\Practice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MatterRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'matter_type' => ['required', Rule::in(['litigation', 'advisory', 'drafting', 'creator_retainer', 'other'])],
            'practice_area_id' => ['nullable', 'integer', 'exists:practice_areas,id'],
            'court_id' => ['nullable', 'integer', 'exists:courts,id'],
            'case_type_id' => ['nullable', 'integer', 'exists:case_types,id'],
            'case_number' => ['nullable', 'string', 'max:40'],
            'case_year' => ['nullable', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'cnr_number' => ['nullable', 'string', 'size:16', 'alpha_num'],
            'filing_date' => ['nullable', 'date', 'before_or_equal:today'],
            'stage' => ['nullable', 'string', 'max:80'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'description' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
