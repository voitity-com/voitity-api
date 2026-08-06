<?php

namespace App\Http\Requests\Integrations;

use App\Enums\IntegrationDestinationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOtherMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxSizeKb = max(
            (int) config('other.max_image_size_mb', 10),
            (int) config('other.max_video_size_mb', 100),
        ) * 1024;

        return [
            'file' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm',
                "max:{$maxSizeKb}",
            ],
            'description' => ['required', 'string', 'max:2000'],
            'link' => ['required', 'url:http,https', 'max:2048'],
            'destination_type' => ['required', Rule::enum(IntegrationDestinationType::class)],
            'custom_destination_label' => [
                'nullable',
                'required_if:destination_type,'.IntegrationDestinationType::Other->value,
                'string',
                'max:60',
            ],
            'selected' => ['sometimes', 'boolean'],
            'rights_confirmed' => ['required', 'accepted'],
        ];
    }
}
