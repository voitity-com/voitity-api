<?php

namespace App\Http\Requests\ProfileKnowledge;

use App\Enums\ProfileFactVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileFactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'string', 'max:80'],
            'text' => ['sometimes', 'string', 'max:2000'],
            'visibility' => ['sometimes', Rule::enum(ProfileFactVisibility::class)],
            'approved' => ['sometimes', 'boolean'],
            'indexed' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
