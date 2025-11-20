<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleOAuthRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'google_id' => 'required|string',
            'email' => 'required|email',
            'name' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'avatar' => 'nullable|url',
            'access_token' => 'required|string', // Token de Google para verificar
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'google_id.required' => 'Google ID is required.',
            'email.required' => 'Email is required.',
            'email.email' => 'Email must be a valid email address.',
            'name.required' => 'Name is required.',
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',
            'access_token.required' => 'Google access token is required.',
        ];
    }
}
