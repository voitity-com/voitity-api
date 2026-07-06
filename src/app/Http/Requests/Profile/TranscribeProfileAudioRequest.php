<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TranscribeProfileAudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audio' => [
                'required',
                'file',
                'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/wave,audio/x-wav,audio/vnd.wave,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,video/webm,video/mp4',
                'max:51200',
            ],
            'field' => ['nullable', Rule::in(['description', 'personality'])],
            'language' => ['nullable', 'string', 'max:10'],
        ];
    }
}
