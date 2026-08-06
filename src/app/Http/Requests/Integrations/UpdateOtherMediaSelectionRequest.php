<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOtherMediaSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'media' => ['required', 'array', 'min:1'],
            'media.*.id' => ['required', 'integer', 'distinct'],
            'media.*.selected' => ['required', 'boolean'],
        ];
    }
}
