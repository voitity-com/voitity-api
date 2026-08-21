<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['nullable', 'required_without:fields', 'string', 'max:10000'],
            'fields' => ['nullable', 'required_without:message', 'array:full_name,email,phone,whatsapp,company,website,project_summary', 'min:1'],
            'fields.full_name' => ['sometimes', 'string', 'max:160'],
            'fields.email' => ['sometimes', 'email:rfc', 'max:255'],
            'fields.phone' => ['sometimes', 'string', 'regex:/^\+[1-9][0-9\s().-]{7,20}$/'],
            'fields.whatsapp' => ['sometimes', 'string', 'regex:/^\+[1-9][0-9\s().-]{7,20}$/'],
            'fields.company' => ['sometimes', 'string', 'max:160'],
            'fields.website' => ['sometimes', 'url:http,https', 'max:2048'],
            'fields.project_summary' => ['sometimes', 'string', 'max:10000'],
            'locale' => ['nullable', 'string', Rule::in(['es', 'en'])],
        ];
    }

    public function messages(): array
    {
        if ($this->input('locale') === 'en') {
            return [
                'fields.phone.regex' => 'Phone must include + and the country code, for example +573001234567.',
                'fields.whatsapp.regex' => 'WhatsApp must include + and the country code, for example +573001234567.',
            ];
        }

        return [
            'fields.phone.regex' => 'El teléfono debe incluir + y el indicativo de país, por ejemplo +573001234567.',
            'fields.whatsapp.regex' => 'WhatsApp debe incluir + y el indicativo de país, por ejemplo +573001234567.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('locale')) {
            $this->merge(['locale' => mb_strtolower(trim((string) $this->input('locale')))]);
        }
    }
}
