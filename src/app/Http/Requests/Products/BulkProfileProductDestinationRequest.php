<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkProfileProductDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:15'],
            'product_ids.*' => ['required', 'integer', 'distinct'],
            'destination_type' => ['required', Rule::in(['whatsapp', 'telegram'])],
            'country_code' => ['required', 'regex:/^\+?\d{1,4}$/'],
            'phone_number' => ['required', 'regex:/^[\d\s().-]{6,24}$/'],
        ];
    }
}
