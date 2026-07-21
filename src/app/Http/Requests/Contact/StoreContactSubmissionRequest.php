<?php

namespace App\Http\Requests\Contact;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreContactSubmissionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone_country_code' => ['required', 'string', 'max:8', 'regex:/^\+\d{1,4}$/'],
            'phone_number' => ['required', 'string', 'min:5', 'max:32', 'regex:/^[+0-9\s().-]+$/'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
            'locale' => ['nullable', 'string', 'in:en,es'],
            'source' => ['nullable', 'string', 'max:80'],
            'consent_accepted' => ['accepted'],
            'captcha_token' => [(bool) config('captcha.enabled', false) ? 'required' : 'nullable', 'string', 'max:4096'],
            'page_url' => ['nullable', 'url', 'max:2048'],
            'referrer' => ['nullable', 'url', 'max:2048'],
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
            'phone_country_code.required' => 'Country calling code is required.',
            'phone_country_code.regex' => 'Country calling code must be a valid international prefix.',
            'phone_number.required' => 'Phone number is required.',
            'phone_number.regex' => 'Phone number format is invalid.',
            'message.required' => 'Message is required.',
            'message.min' => 'Message must be at least 10 characters.',
            'consent_accepted.accepted' => 'Consent is required.',
            'captcha_token.required' => 'Captcha verification is required.',
            'locale.in' => 'Locale must be English or Spanish.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $locale = Str::lower($this->trimString($this->input('locale')) ?? '');
        $locale = in_array($locale, ['en', 'es'], true) ? $locale : 'en';

        $this->merge([
            'name' => $this->trimString($this->input('name')),
            'email' => Str::lower($this->trimString($this->input('email')) ?? ''),
            'phone_country_code' => $this->normalizeCountryCode($this->input('phone_country_code')),
            'phone_number' => $this->normalizePhoneNumber($this->input('phone_number')),
            'message' => $this->trimString($this->input('message')),
            'locale' => $locale,
            'source' => $this->trimString($this->input('source')) ?? 'landing_page',
            'captcha_token' => $this->trimString($this->input('captcha_token')),
            'page_url' => $this->trimString($this->input('page_url')),
            'referrer' => $this->trimString($this->input('referrer')),
        ]);
    }

    private function normalizeCountryCode(mixed $value): ?string
    {
        $value = $this->trimString($value);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits ? '+'.$digits : null;
    }

    private function normalizePhoneNumber(mixed $value): ?string
    {
        $value = $this->trimString($value);

        return $value === null ? null : preg_replace('/\s+/', ' ', $value);
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
