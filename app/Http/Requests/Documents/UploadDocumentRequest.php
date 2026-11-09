<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UploadDocumentRequest extends FormRequest
{
    public const CATEGORIES = ['pleading', 'order', 'evidence', 'identity', 'property', 'notice', 'contract', 'invoice', 'settlement', 'verification', 'other'];

    /** @return list<mixed> */
    public static function fileRules(): array
    {
        return ['required', File::types(['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt'])->max(25 * 1024)];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => self::fileRules(),
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'confidentiality' => ['sometimes', Rule::in(['normal', 'confidential', 'privileged'])],
            'folder_id' => ['nullable', 'uuid'],
        ];
    }
}
