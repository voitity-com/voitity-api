<?php

namespace App\Http\Requests\Profile;

use App\Models\ProfileConversationMessage;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateProfileConversationMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ProfileConversationMessage::TYPE_INITIAL => ['sometimes', 'array'],
            ProfileConversationMessage::TYPE_INITIAL.'.text' => ['nullable', 'string', 'max:1000'],
            ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER => ['sometimes', 'array'],
            ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER.'.text' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
