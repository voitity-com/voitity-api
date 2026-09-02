<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class EmailSignUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
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
            'checkout_intent' => ['nullable', 'array:intent,plan,cycle,locale,landingVariant,attribution,clickIdentifiers'],
            'checkout_intent.intent' => ['required_with:checkout_intent', 'string', 'in:checkout,trial'],
            'checkout_intent.plan' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'],
            'checkout_intent.cycle' => ['nullable', 'string', 'in:month,year'],
            'checkout_intent.locale' => ['nullable', 'string', 'in:en,es'],
            'checkout_intent.landingVariant' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/'],
            'checkout_intent.attribution' => ['nullable', 'array:utm_source,utm_medium,utm_campaign,utm_term,utm_content'],
            'checkout_intent.attribution.utm_source' => ['nullable', 'string', 'max:255'],
            'checkout_intent.attribution.utm_medium' => ['nullable', 'string', 'max:255'],
            'checkout_intent.attribution.utm_campaign' => ['nullable', 'string', 'max:255'],
            'checkout_intent.attribution.utm_term' => ['nullable', 'string', 'max:255'],
            'checkout_intent.attribution.utm_content' => ['nullable', 'string', 'max:255'],
            'checkout_intent.clickIdentifiers' => ['nullable', 'array:gclid,gbraid,wbraid'],
            'checkout_intent.clickIdentifiers.gclid' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9_.:-]+$/i'],
            'checkout_intent.clickIdentifiers.gbraid' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9_.:-]+$/i'],
            'checkout_intent.clickIdentifiers.wbraid' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9_.:-]+$/i'],
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
