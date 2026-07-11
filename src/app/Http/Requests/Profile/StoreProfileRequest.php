<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('description')) {
            $this->merge([
                'description' => trim((string) $this->input('description')),
            ]);
        }

        if ($this->has('alias')) {
            $this->merge([
                'alias' => trim((string) $this->input('alias')),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'alias' => [
                'required',
                'string',
                'max:100',
                Rule::unique('profiles', 'alias')->whereNull('deleted_at'),
            ],
            'description' => 'required|string|max:500',
            'genre' => 'required|string|max:10',
            'personality' => 'required|string|max:200',
            'profession_key' => ['sometimes', 'string', 'max:80', Rule::in(array_keys(config('profile-professions.templates', [])))],
            'profession_template_version' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
