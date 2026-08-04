<?php

namespace App\Http\Requests\Message;

use Illuminate\Foundation\Http\FormRequest;

class StoreAudioMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audio' => [
                'required',
                'file',
                'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/wave,audio/x-wav,audio/vnd.wave,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,video/webm,video/mp4',
                'max:'.max(1, (int) config('subscriptions.audio_message_max_size_kb', 10240)),
            ],
            'chat_id' => ['nullable', 'integer', 'exists:chats,id'],
            'audio_response_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
