<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkProfileProductStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(['draft', 'published'])],
        ];
    }
}
