<?php

namespace App\Services;

use App\Classes\VoiceService\VoiceManager;
use App\Classes\VoiceService\VoiceService;
use App\Models\Profile;
use App\Models\ProfileConversationMessage;
use App\Models\Voice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ProfileConversationMessageService
{
    public const TYPES = [
        ProfileConversationMessage::TYPE_INITIAL,
        ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER,
    ];

    public function __construct(private readonly VoiceManager $voiceManager) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function resolvedMessages(Profile $profile): array
    {
        $profile->loadMissing('conversationMessages', 'voices');

        return [
            ProfileConversationMessage::TYPE_INITIAL => $this->resolvedMessage(
                $profile,
                ProfileConversationMessage::TYPE_INITIAL
            ),
            ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER => $this->resolvedMessage(
                $profile,
                ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    public function updateMessages(Profile $profile, array $payload): array
    {
        $updatedTypes = [];

        foreach (self::TYPES as $type) {
            if (! array_key_exists($type, $payload) || ! is_array($payload[$type])) {
                continue;
            }

            $this->updateMessageText($profile, $type, $payload[$type]['text'] ?? null);
            $updatedTypes[] = $type;
        }

        $freshProfile = $profile->fresh(['conversationMessages', 'voices']);

        foreach ($updatedTypes as $type) {
            $this->generateMissingAudioForType($freshProfile, $type);
            $freshProfile = $freshProfile->fresh(['conversationMessages', 'voices']);
        }

        return $this->resolvedMessages($freshProfile);
    }

    public function generateAudio(Profile $profile, string $type): ProfileConversationMessage
    {
        $this->assertValidType($type);

        $text = $this->effectiveText($profile, $type);

        if ($text === null) {
            throw new RuntimeException('Message text is required before generating audio.');
        }

        $voice = $this->activeConfiguredVoice($profile);

        if (! $voice) {
            throw new RuntimeException('A cloned active voice is required before generating audio.');
        }

        $message = $this->recordFor($profile, $type);

        $message->forceFill([
            'status' => ProfileConversationMessage::STATUS_PENDING,
            'text' => $type === ProfileConversationMessage::TYPE_INITIAL && $message->text === null ? null : $text,
            'text_hash' => $this->textHash($text),
            'voice_id' => $voice->id,
            'metadata' => [
                ...($message->metadata ?? []),
                'generation_started_at' => now()->toIso8601String(),
            ],
        ])->save();

        try {
            $driverName = $voice->source ?: null;
            $voiceClient = $this->voiceManager->driver($driverName);
            $voiceService = new VoiceService($voice, $voiceClient);
            $generatedAudio = $voiceService->generateAudio($text);
        } catch (\Throwable $e) {
            $message->forceFill([
                'status' => ProfileConversationMessage::STATUS_FAILED,
                'metadata' => [
                    ...($message->metadata ?? []),
                    'generation_failed_at' => now()->toIso8601String(),
                    'generation_error' => $e->getMessage(),
                ],
            ])->save();

            throw new RuntimeException('Audio generation failed.');
        }

        if (! $generatedAudio->isSuccessful() || ! $generatedAudio->getAudioUrl()) {
            $message->forceFill([
                'status' => ProfileConversationMessage::STATUS_FAILED,
                'metadata' => [
                    ...($message->metadata ?? []),
                    'generation_failed_at' => now()->toIso8601String(),
                    'generation_status' => $generatedAudio->status,
                    'generation_metadata' => $generatedAudio->metadata,
                ],
            ])->save();

            throw new RuntimeException('Audio generation failed.');
        }

        $message->forceFill([
            'audio_url' => $generatedAudio->getAudioUrl(),
            'audio_path' => null,
            'audio_disk' => null,
            'audio_source' => ProfileConversationMessage::AUDIO_SOURCE_GENERATED,
            'audio_format' => $generatedAudio->audioFormat,
            'voice_id' => $voice->id,
            'status' => ProfileConversationMessage::STATUS_READY,
            'text_hash' => $this->textHash($text),
            'metadata' => [
                ...($message->metadata ?? []),
                'generated_at' => now()->toIso8601String(),
                'generation_status' => $generatedAudio->status,
                'generation_metadata' => $generatedAudio->metadata,
                'duration' => $generatedAudio->duration,
            ],
        ])->save();

        return $message->refresh();
    }

    public function uploadAudio(Profile $profile, string $type, UploadedFile $audio): ProfileConversationMessage
    {
        $this->assertValidType($type);

        $text = $this->effectiveText($profile, $type);

        if ($text === null) {
            throw new RuntimeException('Message text is required before uploading audio.');
        }

        $diskName = $this->audioDisk();
        $folder = trim($this->audioFolder().'/'.$profile->id.'/'.$type, '/');
        $extension = $audio->guessExtension() ?: $audio->getClientOriginalExtension() ?: 'webm';
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $audio->storeAs($folder, $filename, [
            'disk' => $diskName,
            'visibility' => $this->audioVisibility(),
        ]);

        if (! is_string($path)) {
            throw new RuntimeException('Unable to store conversation audio.');
        }

        $message = $this->recordFor($profile, $type);
        $message->forceFill([
            'audio_url' => Storage::disk($diskName)->url($path),
            'audio_path' => $path,
            'audio_disk' => $diskName,
            'audio_source' => ProfileConversationMessage::AUDIO_SOURCE_RECORDED,
            'audio_format' => $extension,
            'voice_id' => null,
            'status' => ProfileConversationMessage::STATUS_READY,
            'text_hash' => $this->textHash($text),
            'metadata' => [
                ...($message->metadata ?? []),
                'uploaded_at' => now()->toIso8601String(),
                'original_name' => $audio->getClientOriginalName(),
                'mime_type' => $audio->getClientMimeType(),
                'size' => $audio->getSize(),
            ],
        ])->save();

        return $message->refresh();
    }

    public function clearAudio(Profile $profile, string $type): ProfileConversationMessage
    {
        $this->assertValidType($type);

        $message = $this->recordFor($profile, $type);
        $message->forceFill([
            'audio_url' => null,
            'audio_path' => null,
            'audio_disk' => null,
            'audio_source' => null,
            'audio_format' => null,
            'voice_id' => null,
            'status' => ProfileConversationMessage::STATUS_READY,
            'metadata' => [
                ...($message->metadata ?? []),
                'audio_cleared_at' => now()->toIso8601String(),
            ],
        ])->save();

        return $message->refresh();
    }

    public function generateMissingAudiosForVoice(Voice $voice): void
    {
        if (! $voice->profile_id || ! filled($voice->source_voice_id) || ! filled($voice->source)) {
            return;
        }

        /** @var Profile|null $profile */
        $profile = Profile::query()
            ->with(['conversationMessages', 'voices'])
            ->find($voice->profile_id);

        if (! $profile) {
            return;
        }

        foreach (self::TYPES as $type) {
            $resolved = $this->resolvedMessage($profile, $type);

            if (! $resolved['enabled']) {
                continue;
            }

            if (($resolved['audio_source'] ?? null) === ProfileConversationMessage::AUDIO_SOURCE_RECORDED) {
                continue;
            }

            if (($resolved['audio_source'] ?? null) === ProfileConversationMessage::AUDIO_SOURCE_GENERATED
                && (string) ($resolved['voice_id'] ?? '') === (string) $voice->id
            ) {
                continue;
            }

            try {
                $this->generateAudio($profile, $type);
            } catch (\Throwable) {
                // The UI exposes failed state; cloning must not fail because a default message audio failed.
            }
        }
    }

    public function shouldUseFallbackAnswer(Profile $profile, string $answer): bool
    {
        $fallback = $this->resolvedMessage($profile, ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER);

        if (! $fallback['enabled']) {
            return false;
        }

        return str_contains($answer, '[[BIGMELO_NO_ANSWER]]')
            || str_contains(mb_strtolower($answer), 'no tengo esa información')
            || str_contains(mb_strtolower($answer), 'no tengo informacion')
            || str_contains(mb_strtolower($answer), 'do not have that information')
            || str_contains(mb_strtolower($answer), "don't have that information");
    }

    public function stripNoAnswerMarker(string $answer): string
    {
        return trim(str_replace('[[BIGMELO_NO_ANSWER]]', '', $answer));
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedMessage(Profile $profile, string $type): array
    {
        $this->assertValidType($type);

        $message = $this->loadedRecordFor($profile, $type);
        $text = $this->effectiveText($profile, $type, $message);
        $enabled = $text !== null;
        $audio = $enabled ? $this->effectiveAudio($profile, $type, $message, $text) : [];

        return [
            'type' => $type,
            'enabled' => $enabled,
            'text' => $text,
            'audio_url' => $audio['audio_url'] ?? null,
            'audio_source' => $audio['audio_source'] ?? null,
            'audio_format' => $audio['audio_format'] ?? null,
            'voice_id' => $audio['voice_id'] ?? null,
            'status' => $message?->status ?? ProfileConversationMessage::STATUS_READY,
            'customized' => $message !== null && filled($message->text),
            'updated_at' => $message?->updated_at?->toJSON(),
            'metadata' => $message?->metadata ?? [],
        ];
    }

    private function updateMessageText(Profile $profile, string $type, mixed $rawText): void
    {
        $this->assertValidType($type);

        $text = is_string($rawText) ? trim($rawText) : null;
        $text = $text === '' ? null : $text;
        $message = $this->recordFor($profile, $type);
        $previousHash = $message->text_hash;
        $effectiveTextForHash = $text ?? (
            $type === ProfileConversationMessage::TYPE_INITIAL ? $this->defaultInitialText($profile) : null
        );
        $nextHash = $effectiveTextForHash ? $this->textHash($effectiveTextForHash) : null;

        $message->text = $text;
        $message->status = ProfileConversationMessage::STATUS_READY;

        if ($previousHash !== $nextHash) {
            $message->audio_url = null;
            $message->audio_path = null;
            $message->audio_disk = null;
            $message->audio_source = null;
            $message->audio_format = null;
            $message->voice_id = null;
        }

        $message->text_hash = $nextHash;
        $message->metadata = [
            ...($message->metadata ?? []),
            'text_updated_at' => now()->toIso8601String(),
        ];
        $message->save();
    }

    private function generateMissingAudioForType(Profile $profile, string $type): void
    {
        $resolved = $this->resolvedMessage($profile, $type);

        if (! $resolved['enabled']) {
            return;
        }

        if (($resolved['audio_source'] ?? null) === ProfileConversationMessage::AUDIO_SOURCE_RECORDED) {
            return;
        }

        if (($resolved['audio_source'] ?? null) === ProfileConversationMessage::AUDIO_SOURCE_GENERATED) {
            return;
        }

        if (! $this->activeConfiguredVoice($profile)) {
            return;
        }

        try {
            $this->generateAudio($profile, $type);
        } catch (\Throwable) {
            // Saving text should not fail because a provider could not generate audio.
        }
    }

    private function recordFor(Profile $profile, string $type): ProfileConversationMessage
    {
        return ProfileConversationMessage::firstOrCreate([
            'profile_id' => $profile->id,
            'type' => $type,
        ], [
            'status' => ProfileConversationMessage::STATUS_READY,
        ]);
    }

    private function loadedRecordFor(Profile $profile, string $type): ?ProfileConversationMessage
    {
        if ($profile->relationLoaded('conversationMessages')) {
            return $profile->conversationMessages->first(
                fn (ProfileConversationMessage $message) => $message->type === $type
            );
        }

        return $profile->conversationMessages()->where('type', $type)->first();
    }

    private function effectiveText(
        Profile $profile,
        string $type,
        ?ProfileConversationMessage $message = null
    ): ?string {
        $message ??= $this->loadedRecordFor($profile, $type);
        $text = trim((string) ($message?->text ?? ''));

        if ($text !== '') {
            return $text;
        }

        if ($type === ProfileConversationMessage::TYPE_INITIAL) {
            return $this->defaultInitialText($profile);
        }

        return null;
    }

    private function defaultInitialText(Profile $profile): string
    {
        $name = trim((string) $profile->name);
        $name = $name !== '' ? $name : 'Bigmelo';

        return "Hola, soy {$name}. Pregúntame sobre mi trabajo, mis proyectos o lo que quieres conocer de mí.";
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveAudio(
        Profile $profile,
        string $type,
        ?ProfileConversationMessage $message,
        string $text
    ): array {
        if ($message && $message->audio_url) {
            if ($message->audio_source === ProfileConversationMessage::AUDIO_SOURCE_RECORDED) {
                return $this->audioPayload($message);
            }

            if ($message->audio_source === ProfileConversationMessage::AUDIO_SOURCE_GENERATED) {
                $voice = $this->activeConfiguredVoice($profile);

                if ($voice
                    && (int) $message->voice_id === (int) $voice->id
                    && $message->text_hash === $this->textHash($text)
                ) {
                    return $this->audioPayload($message);
                }
            }
        }

        if ($type === ProfileConversationMessage::TYPE_INITIAL) {
            $defaultAudioUrl = config('profile-conversation.defaults.initial_audio_url');

            if (is_string($defaultAudioUrl) && trim($defaultAudioUrl) !== '') {
                return [
                    'audio_url' => trim($defaultAudioUrl),
                    'audio_source' => ProfileConversationMessage::AUDIO_SOURCE_DEFAULT,
                    'audio_format' => 'mp3',
                    'voice_id' => null,
                ];
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function audioPayload(ProfileConversationMessage $message): array
    {
        return [
            'audio_url' => $message->audio_url,
            'audio_source' => $message->audio_source,
            'audio_format' => $message->audio_format,
            'voice_id' => $message->voice_id,
        ];
    }

    private function activeConfiguredVoice(Profile $profile): ?Voice
    {
        if ($profile->relationLoaded('voices')) {
            return $profile->voices->first(
                fn (Voice $voice): bool => (bool) $voice->active
                    && filled($voice->source_voice_id)
                    && filled($voice->source)
            );
        }

        return $profile->voices()
            ->where('active', true)
            ->whereNotNull('source_voice_id')
            ->where('source_voice_id', '<>', '')
            ->whereNotNull('source')
            ->where('source', '<>', '')
            ->latest('id')
            ->first();
    }

    private function assertValidType(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Invalid conversation message type.');
        }
    }

    private function textHash(string $text): string
    {
        return hash('sha256', trim($text));
    }

    private function audioDisk(): string
    {
        return (string) config('profile-conversation.audio.disk', 'public');
    }

    private function audioFolder(): string
    {
        $folder = trim((string) config('profile-conversation.audio.folder', 'profile-conversation'), '/');

        return $folder !== '' ? $folder : 'profile-conversation';
    }

    private function audioVisibility(): string
    {
        return (string) config('profile-conversation.audio.visibility', 'public');
    }
}
