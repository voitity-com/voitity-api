<?php

namespace App\Http\Requests\Insights;

use App\Enums\ProfileInsightEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfileInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'visitor_id' => ['required', 'uuid'],
            'event_type' => ['required', Rule::enum(ProfileInsightEventType::class)->except([
                ProfileInsightEventType::MediaShown,
                ProfileInsightEventType::ProductShown,
            ])],
            'chat_id' => ['nullable', 'integer', 'min:1'],
            'subject_id' => ['nullable', 'string', 'max:128'],
            'provider' => ['nullable', 'string', 'max:64'],
            'destination_type' => ['nullable', Rule::in(['provider_video', 'provider_channel'])],
            'surface' => ['nullable', 'string', Rule::in([
                'profile_page', 'product_image', 'product_button', 'chat_media_card',
                'chat_media_modal', 'profile_social_nav', 'chat_social_link',
            ])],
            'media_type' => ['nullable', Rule::in(['image', 'video'])],
            'metadata' => ['nullable', 'array'],
            'metadata.destination_type' => ['nullable', Rule::in(['external_url', 'whatsapp', 'telegram'])],
        ];
    }
}
