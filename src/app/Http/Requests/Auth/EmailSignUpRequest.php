<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class EmailSignUpRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'locale' => ['nullable', 'string', 'in:en,es'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'email.required' => 'Email is required.',
            'email.email' => 'Email must be a valid email address.',
            'email.unique' => 'A user with this email already exists.',
            'locale.in' => 'Locale must be English or Spanish.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $locale = $this->trimString($this->input('locale') ?? $this->input('language'));
        $locale = Str::lower($locale ?? '');
        $locale = in_array($locale, ['en', 'es'], true) ? $locale : 'en';

        $this->merge([
            'name' => $this->trimString($this->input('name')),
            'first_name' => $this->trimString($this->input('first_name')),
            'last_name' => $this->trimString($this->input('last_name')),
            'email' => Str::lower($this->trimString($this->input('email')) ?? ''),
            'locale' => $locale,
        ]);
    }

    private function trimString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
