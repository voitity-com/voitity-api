<?php

namespace App\Http\Requests\Payments;

use App\Enums\SubscriptionPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartSubscriptionTrialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', Rule::enum(SubscriptionPlan::class)],
            'terms_accepted' => ['required', 'accepted'],
            'payment_source_id' => ['required_without:payment_source', 'nullable', 'integer', 'min:1'],
            'payment_source' => ['required_without:payment_source_id', 'nullable', 'array'],
            'payment_source.type' => ['required_with:payment_source', 'string', Rule::in(['CARD'])],
            'payment_source.token' => ['required_with:payment_source', 'string', 'max:255'],
            'payment_source.acceptance_token' => ['required_with:payment_source', 'string', 'max:4096'],
            'payment_source.accept_personal_auth' => ['required_with:payment_source', 'string', 'max:4096'],
            'payment_source.session_id' => ['nullable', 'string', 'max:255'],
            'payment_source.customer_data' => ['nullable', 'array'],
            'payment_source.customer_data.device_id' => ['nullable', 'string', 'max:255'],
            'payment_source.customer_data.full_name' => ['nullable', 'string', 'max:255'],
            'payment_source.customer_data.phone_number' => ['nullable', 'string', 'max:50'],
            'payment_source.metadata' => ['nullable', 'array'],
        ];
    }
}
