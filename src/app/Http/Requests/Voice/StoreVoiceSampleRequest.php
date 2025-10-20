<?php

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

class StoreVoiceSampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:audio/mpeg,audio/wav,audio/mp3', 'max:10240'],
            'language_code' => ['nullable', 'string', 'max:10'],
        ];
    }
}
