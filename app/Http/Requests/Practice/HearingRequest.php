<?php

namespace App\Http\Requests\Practice;

use Illuminate\Foundation\Http\FormRequest;

class HearingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'court_id' => ['required', 'integer', 'exists:courts,id'],
            'court_hall' => ['nullable', 'string', 'max:30'],
            'judge_name' => ['nullable', 'string', 'max:150'],
            'hearing_date' => ['required', 'date'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'item_number' => ['nullable', 'string', 'max:20'],
            'purpose' => ['nullable', 'string', 'max:120'],
        ];
    }
}
