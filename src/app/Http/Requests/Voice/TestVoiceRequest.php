<?php

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

class TestVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
            'text' => ['required', 'string', 'max:5000'],
        ];
    }
}
