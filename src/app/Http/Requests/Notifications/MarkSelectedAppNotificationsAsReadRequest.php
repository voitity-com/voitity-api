<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class MarkSelectedAppNotificationsAsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notification_ids' => ['required', 'array', 'min:1', 'max:50'],
            'notification_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
