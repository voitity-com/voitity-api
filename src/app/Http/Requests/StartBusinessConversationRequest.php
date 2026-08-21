<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartBusinessConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('locale')) {
            $this->merge(['locale' => mb_strtolower(trim((string) $this->input('locale')))]);
        }
    }

    public function rules(): array
    {
        return [
            'visitor_id' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(['es', 'en'])],
        ];
    }
}
