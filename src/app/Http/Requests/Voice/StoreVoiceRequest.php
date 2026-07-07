<?php

namespace App\Http\Requests\Voice;

use App\Models\Profile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'language_code' => ['required', 'string', Rule::in(['es', 'en'])],
            'profile_id' => [
                'nullable',
                'integer',
                'exists:profiles,id',
                function ($attribute, $value, $fail) {
                    $user = $this->user();

                    if ($value && $user) {
                        $profile = Profile::find($value);

                        if (! $profile || (! in_array($user->role, ['admin', 'api'], true) && (int) $profile->user_id !== (int) $user->id)) {
                            $fail('The selected profile does not belong to the authenticated user.');
                        }
                    }
                },
            ],
        ];
    }
}
