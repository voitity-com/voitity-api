<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class PasswordForgotRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'locale' => ['nullable', 'string', 'in:en,es'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email is required.',
            'email.email' => 'Email must be a valid email address.',
            'locale.in' => 'Locale must be English or Spanish.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $locale = Str::lower((string) ($this->input('locale') ?? $this->input('language') ?? 'en'));

        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'locale' => in_array($locale, ['en', 'es'], true) ? $locale : 'en',
        ]);
    }
}
