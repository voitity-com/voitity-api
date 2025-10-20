<?php

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

class StoreVoiceRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:100'],
            'description'   => ['nullable', 'string', 'max:500'],
            'language_code' => ['required', 'string', 'max:10'],
            'profile_id'    => [
                'nullable', 
                'integer', 
                'exists:profiles,id',
                function ($attribute, $value, $fail) {
                    if ($value && $this->user()) {
                        $profile = \App\Models\Profile::find($value);
                        if (!$profile || $profile->user_id !== $this->user()->id) {
                            $fail('The selected profile does not belong to the authenticated user.');
                        }
                    }
                }
            ],
        ];
    }
}
