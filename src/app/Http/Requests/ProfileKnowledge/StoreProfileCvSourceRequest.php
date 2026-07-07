<?php

namespace App\Http\Requests\ProfileKnowledge;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfileCvSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'nullable',
                'required_without:text',
                'file',
                'mimetypes:application/pdf,text/plain,text/markdown,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'max:10240',
            ],
            'text' => ['nullable', 'required_without:file', 'string', 'max:50000'],
            'name' => ['nullable', 'string', 'max:150'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
