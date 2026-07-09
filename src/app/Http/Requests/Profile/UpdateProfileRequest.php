<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:100',
            'alias' => 'sometimes|nullable|string|max:100',
            'description' => 'sometimes|string|max:500',
            'genre' => 'sometimes|string|max:10',
            'personality' => 'sometimes|string|max:200',
            'active' => ['prohibited'],
            'status' => ['prohibited'],
            'profession_key' => ['sometimes', 'string', 'max:80', Rule::in(array_keys(config('profile-professions.templates', [])))],
            'profession_template_version' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
