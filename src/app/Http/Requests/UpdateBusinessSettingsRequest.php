<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'lead_recipient_email' => ['nullable', 'email', 'max:255'],
            'locale' => ['nullable', Rule::in(['es', 'en'])],
            'widget_enabled' => ['nullable', 'boolean'],
            'widget_title' => ['nullable', 'string', 'max:255'],
            'widget_button_label' => ['nullable', 'string', 'max:255'],
            'widget_welcome_message' => ['nullable', 'string', 'max:2000'],
            'widget_primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'widget_position' => ['nullable', Rule::in(['bottom-right', 'bottom-left'])],
        ];
    }
}
