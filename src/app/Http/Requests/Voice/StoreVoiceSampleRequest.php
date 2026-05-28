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
            'file' => [
                'required',
                'file',
                'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/wave,audio/x-wav,audio/vnd.wave,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac',
                'max:51200',
            ],
            'language_code' => ['nullable', 'string', 'max:10'],
        ];
    }
}
