<?php

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'language_code' => ['sometimes', 'required', 'string', Rule::in(['es', 'en'])],
        ];
    }
}
