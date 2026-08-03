<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateYouTubeMediaSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'media' => ['required', 'array'],
            'media.*.id' => ['required', 'integer'],
            'media.*.selected' => ['nullable', 'boolean'],
            'media.*.observation' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
