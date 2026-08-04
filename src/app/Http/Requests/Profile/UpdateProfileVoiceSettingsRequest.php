<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileVoiceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'voice_enabled' => ['required', 'boolean'],
            'voice_autoplay_enabled' => ['required', 'boolean'],
        ];
    }
}
