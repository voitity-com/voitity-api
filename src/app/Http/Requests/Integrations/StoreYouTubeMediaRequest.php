<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class StoreYouTubeMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'video_url' => ['required', 'string', 'max:2048'],
            'description' => ['required', 'string', 'max:1000'],
            'selected' => ['sometimes', 'boolean'],
        ];
    }
}
