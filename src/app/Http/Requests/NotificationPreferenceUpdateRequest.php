<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NotificationPreferenceUpdateRequest extends FormRequest
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
        $keys = array_keys((array) config('notifications.preferences', []));

        return [
            'preferences' => [
                'required',
                'array',
                function (string $attribute, mixed $value, \Closure $fail) use ($keys): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $unknownKeys = array_diff(array_keys($value), $keys);

                    if ($unknownKeys !== []) {
                        $fail('The selected notification preference is invalid.');
                    }
                },
            ],
            'preferences.*' => ['required', 'boolean'],
        ];
    }
}
