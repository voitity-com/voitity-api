<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'alias' => 'nullable|string|max:100',
            'description' => 'required|string|max:500',
            'genre' => 'required|string|max:10',
            'personality' => 'required|string|max:200',
            'profession_key' => ['sometimes', 'string', 'max:80', Rule::in(array_keys(config('profile-professions.templates', [])))],
            'profession_template_version' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
