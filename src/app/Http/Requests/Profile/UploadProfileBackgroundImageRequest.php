<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadProfileBackgroundImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:10240',
            ],
            'template_key' => [
                'sometimes',
                'string',
                Rule::in(array_keys(config('profile-appearance.templates', []))),
            ],
        ];
    }
}
