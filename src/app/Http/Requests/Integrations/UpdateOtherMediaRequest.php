<?php

namespace App\Http\Requests\Integrations;

use App\Enums\IntegrationDestinationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOtherMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:2000'],
            'link' => ['required', 'url:http,https', 'max:2048'],
            'destination_type' => ['required', Rule::enum(IntegrationDestinationType::class)],
            'custom_destination_label' => [
                'nullable',
                'required_if:destination_type,'.IntegrationDestinationType::Other->value,
                'string',
                'max:60',
            ],
            'selected' => ['sometimes', 'boolean'],
        ];
    }
}
