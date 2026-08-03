<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class StoreYouTubeIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel_url' => ['required', 'string', 'max:2048'],
        ];
    }
}
