<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['CARD'])],
            'make_default' => ['sometimes', 'boolean'],
            'token' => ['required', 'string', 'max:255'],
            'acceptance_token' => ['required', 'string', 'max:4096'],
            'accept_personal_auth' => ['required', 'string', 'max:4096'],
            'session_id' => ['nullable', 'string', 'max:255'],
            'customer_data' => ['nullable', 'array'],
            'customer_data.device_id' => ['nullable', 'string', 'max:255'],
            'customer_data.full_name' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'metadata.card' => ['nullable', 'array'],
            'metadata.card.brand' => ['nullable', 'string', 'max:50'],
            'metadata.card.last_four' => ['nullable', 'digits:4'],
            'metadata.card.exp_month' => ['nullable', 'integer', 'between:1,12'],
            'metadata.card.exp_year' => ['nullable', 'integer', 'between:2020,2200'],
            'metadata.wompi_environment' => ['nullable', 'string', 'max:30'],
        ];
    }
}
