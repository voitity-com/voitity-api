<?php

namespace App\Http\Requests\Support;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportRequest extends FormRequest
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
            'profile_id' => [
                'nullable',
                'integer',
                Rule::exists('profiles', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('user_id', $this->user()?->getAuthIdentifier())
                        ->whereNull('deleted_at')
                ),
            ],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'profile_id.exists' => 'The selected profile does not belong to the authenticated user.',
            'description.required' => 'Description is required.',
            'description.min' => 'Description must be at least 10 characters.',
            'description.max' => 'Description may not be greater than 3000 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $description = $this->input('description');
        $profileId = $this->input('profile_id');

        $this->merge([
            'description' => is_string($description) ? trim($description) : $description,
            'profile_id' => $profileId === '' ? null : $profileId,
        ]);
    }
}
