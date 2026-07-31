<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileProductsSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'recommendation_guidance' => [
                'sometimes',
                'nullable',
                'string',
                'max:'.max(1, (int) config('products.recommendation_guidance_max_length', 1500)),
            ],
        ];
    }
}
