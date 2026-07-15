<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class UpdateProfileRequest extends FormRequest
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

        if ($this->has('locale')) {
            $locale = Str::lower(trim((string) $this->input('locale')));
            $this->merge([
                'locale' => in_array($locale, ['en', 'es'], true) ? $locale : $this->input('locale'),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $profile = $this->route('profile');
        $profileId = is_object($profile) && method_exists($profile, 'getKey') ? $profile->getKey() : $profile;

        return [
            'name' => 'sometimes|string|max:100',
            'alias' => [
                'required_with:name,description,genre,personality,profession_key,profession_template_version',
                'filled',
                'string',
                'max:100',
                Rule::unique('profiles', 'alias')->ignore($profileId)->whereNull('deleted_at'),
            ],
            'description' => 'sometimes|required|string|max:500',
            'genre' => 'sometimes|string|max:10',
            'personality' => 'sometimes|string|max:200',
            'locale' => ['sometimes', 'required', 'string', Rule::in(['en', 'es'])],
            'active' => ['prohibited'],
            'status' => ['prohibited'],
            'profession_key' => ['sometimes', 'string', 'max:80', Rule::in(array_keys(config('profile-professions.templates', [])))],
            'profession_template_version' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
