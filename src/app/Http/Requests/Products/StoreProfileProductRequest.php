<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfileProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxImageKb = max(1, (int) config('products.max_image_size_mb', 10)) * 1024;

        return [
            'name' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:2000'],
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', "max:{$maxImageKb}"],
            'destination_type' => ['required', Rule::in(['external_url', 'whatsapp', 'telegram'])],
            'destination_url' => ['nullable', 'required_if:destination_type,external_url', 'url:http,https', 'max:2048'],
            'country_code' => ['nullable', 'required_unless:destination_type,external_url', 'regex:/^\+?\d{1,4}$/'],
            'phone_number' => ['nullable', 'required_unless:destination_type,external_url', 'regex:/^[\d\s().-]{6,24}$/'],
            'status' => ['nullable', Rule::in(['draft', 'published'])],
        ];
    }
}
