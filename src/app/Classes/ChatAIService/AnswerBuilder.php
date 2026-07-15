<?php

namespace App\Classes\ChatAIService;

use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Classes\VoiceService\VoiceManager;
use App\Classes\VoiceService\VoiceService;
use App\Enums\SubscriptionUsageType;
use App\Events\Subscriptions\SubscriptionUsageRequested;
use App\Exceptions\ChatAIService\ChatAIAnswerGenerationFailed;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use App\Models\Voice;
use App\Services\Integrations\InstagramIntegrationService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\ProfileConversationMessageService;
use Illuminate\Support\Facades\Log;

class AnswerBuilder
{
    public function __construct(
        private readonly ChatAIClient $chatAIClient,
        private readonly VoiceManager $voiceManager,
        private readonly ?ProfileConversationMessageService $conversationMessages = null
    ) {}

    public function getAnswer(Profile $profile, Message $question): AnswerResponse
    {
        $chatAIAnswer = $this->chatAIClient->getAnswer(
            $profile,
            $question->text,
            $question->chat_id,
            $question->id
        );

        if ($profile->user_id && $chatAIAnswer->source === 'openai') {
            event(new SubscriptionUsageRequested(
                userId: $profile->user_id,
                usageType: SubscriptionUsageType::ChatOpenAiCall,
                amounts: ['chat_messages' => 1],
                profileId: $profile->id,
                sourceType: Message::class,
                sourceId: (string) $question->id,
                idempotencyKey: "chat-openai:message:{$question->id}",
                metadata: [
                    'status' => $chatAIAnswer->status,
                    'confidence' => $chatAIAnswer->confidence,
                ]
            ));
        }

        if (! $chatAIAnswer->isSuccessful() || ! $chatAIAnswer->hasAnswer()) {
            throw new ChatAIAnswerGenerationFailed($chatAIAnswer);
        }

        $rawAnswerText = $chatAIAnswer->answer;
        $answerText = $this->conversationMessages()->stripNoAnswerMarker($rawAnswerText);
        $source = $chatAIAnswer->source;
        $audioPayload = null;
        $usesPreconfiguredAnswer = false;
        $mediaPayload = $this->mediaPayloadForQuestion($profile, $question);

        if ($mediaPayload !== []) {
            $answerText = $this->mediaFallbackAnswer($mediaPayload, $profile);
        } elseif ($this->conversationMessages()->shouldUseFallbackAnswer($profile, $rawAnswerText)) {
            if ($mediaPayload === []) {
                $fallback = $this->conversationMessages()->resolvedMessage(
                    $profile,
                    \App\Models\ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER
                );
                $answerText = (string) $fallback['text'];
                $source = 'profile_conversation_message';
                $audioPayload = $this->preconfiguredAudioPayload($fallback);
                $usesPreconfiguredAnswer = true;
            }
        }

        $displayAnswerText = $this->appendMediaHint($answerText, $mediaPayload, $profile);
        $audioText = $this->spokenTextForAnswer($displayAnswerText);

        if ($audioPayload === null && ! $usesPreconfiguredAnswer) {
            $audio = $this->getAudio($profile, $audioText);
            $audioUrl = $audio?->getAudioUrl();

            $audioPayload = $audio ? [
                'audio_url' => $audioUrl,
                'status' => $audio->status,
                'metadata' => array_merge($audio->metadata, [
                    'spoken_text' => $audioText,
                ]),
            ] : null;
        }

        $audioUrl = $audioPayload['audio_url'] ?? null;

        $answerMessage = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $question->chat_id,
            'text' => $displayAnswerText,
            'type' => 'answer',
            'source' => $source,
            'audio' => $audioUrl,
            'data' => [
                'chat_ai' => $chatAIAnswer->toArray(),
                'audio' => $audioPayload,
                'media' => $mediaPayload,
            ],
        ]);

        return new AnswerResponse($answerMessage, $chatAIAnswer, $audioPayload, $mediaPayload);
    }

    public function getAudio(Profile $profile, string $text): ?VoiceClientGeneratedAudio
    {
        if (trim($text) === '') {
            return null;
        }

        /** @var Voice|null $voice */
        $voice = $profile->voices()->where('active', true)->first();

        if (! $voice) {
            return null;
        }

        try {
            $driverName = $voice->source ?: null;
            $voiceClient = $this->voiceManager->driver($driverName);
        } catch (\Throwable $e) {
            $this->notifyAudioGenerationFailed($profile, $e->getMessage());

            Log::warning('Unable to resolve voice driver for profile.', [
                'profile_id' => $profile->id,
                'voice_id' => $voice->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        try {
            $voiceService = new VoiceService($voice, $voiceClient);

            $audio = $voiceService->generateAudio($text);

            if ($audio->isFailed()) {
                $this->notifyAudioGenerationFailed($profile, $audio->status);
            }

            return $audio;
        } catch (\Throwable $e) {
            $this->notifyAudioGenerationFailed($profile, $e->getMessage());

            Log::warning('Audio generation failed.', [
                'profile_id' => $profile->id,
                'voice_id' => $voice->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function notifyAudioGenerationFailed(Profile $profile, string $reason): void
    {
        $profile->loadMissing('user');

        if (! $profile->user instanceof User) {
            return;
        }

        app(NotificationDispatcher::class)->send($profile->user, 'audio_response_generation_failed', [
            'profile' => $profile->name ?: "Profile {$profile->id}",
            'profile_id' => $profile->id,
            'reason' => $reason,
            'action_url' => "/dashboard/profiles/{$profile->id}/voice",
        ]);
        app(NotificationDispatcher::class)->sendToAdmins('external_integration_error', [
            'service' => 'Voice audio provider',
            'message' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function preconfiguredAudioPayload(array $message): ?array
    {
        if (empty($message['audio_url'])) {
            return null;
        }

        return [
            'audio_url' => $message['audio_url'],
            'status' => $message['status'] ?? 'ready',
            'metadata' => [
                'source' => $message['audio_source'] ?? null,
                'voice_id' => $message['voice_id'] ?? null,
                'format' => $message['audio_format'] ?? null,
                'preconfigured' => true,
            ],
        ];
    }

    private function conversationMessages(): ProfileConversationMessageService
    {
        return $this->conversationMessages ?? app(ProfileConversationMessageService::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mediaPayloadForQuestion(Profile $profile, Message $question): array
    {
        if (! $this->looksLikeMediaRequest($question->text)) {
            return [];
        }

        $media = app(InstagramIntegrationService::class)->selectedMediaForPrompt($profile);

        if ($media === []) {
            return [];
        }

        $index = max(0, ((int) $question->id - 1) % count($media));
        $item = $media[$index];

        return [[
            'type' => 'instagram_media',
            'provider' => $item['provider_label'] ?? $item['provider'] ?? 'Instagram',
            'provider_key' => $item['provider_key'] ?? 'instagram',
            'provider_label' => $item['provider_label'] ?? $item['provider'] ?? 'Instagram',
            'image_url' => $item['image_url'] ?? null,
            'permalink' => $item['permalink'] ?? null,
            'caption' => $item['caption'] ?? null,
            'observation' => $item['observation'] ?? null,
            'taken_at' => $item['taken_at'] ?? null,
        ]];
    }

    private function looksLikeMediaRequest(string $text): bool
    {
        $normalized = mb_strtolower($text);

        foreach (['foto', 'fotos', 'imagen', 'imágenes', 'instagram', 'post', 'publicación', 'photo', 'picture', 'image'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mediaPayload
     */
    private function appendMediaHint(string $answerText, array $mediaPayload, Profile $profile): string
    {
        $answerText = $this->stripRawUrls($this->normalizeMarkdownMediaSyntax($answerText));

        if ($mediaPayload === []) {
            return $answerText;
        }

        $locale = $this->profileLocale($profile);
        $media = $mediaPayload[0];
        $hint = $this->mediaLinkSentence($media, $locale);

        if ($hint === '') {
            return $answerText;
        }

        $normalizedAnswer = mb_strtolower($answerText);
        $normalizedHint = mb_strtolower($hint);
        $providerLabel = $this->mediaProviderLabel($media);
        $providerMentioned = $providerLabel !== null && str_contains($normalizedAnswer, mb_strtolower($providerLabel));
        $morePhotosMentioned = preg_match('/\b(ver|ve|mirar|mira|see|view|watch|look)\b.*\b(m[aá]s|more)\b/u', $normalizedAnswer) === 1;

        if (($providerMentioned && $morePhotosMentioned) || str_contains($normalizedAnswer, $normalizedHint)) {
            return $answerText;
        }

        if (trim($answerText) === '') {
            return $hint;
        }

        return trim(rtrim($answerText, " \t\n\r\0\x0B.").'. '.$hint);
    }

    /**
     * @param  array<int, array<string, mixed>>  $mediaPayload
     */
    private function mediaFallbackAnswer(array $mediaPayload, Profile $profile): string
    {
        $media = $mediaPayload[0] ?? [];
        $locale = $this->profileLocale($profile);
        $sentences = array_filter([
            $this->mediaContextSentence($media, $locale),
            $this->mediaLinkSentence($media, $locale),
        ]);

        return implode(' ', $sentences);
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function mediaContextSentence(array $media, string $locale): string
    {
        $observation = $this->cleanMediaText($media['observation'] ?? null);
        $caption = $this->cleanMediaText($media['caption'] ?? null);
        $location = $this->extractLocation($observation);

        if ($location === null && $observation === '') {
            $location = $this->extractLocation($caption);
        }

        if ($location !== null) {
            return $locale === 'en'
                ? "This photo was taken in {$location}."
                : "Esta foto fue tomada en {$location}.";
        }

        $detail = $observation ?: $caption;

        if ($detail !== '') {
            $detail = $this->shortenText($detail, 140);

            return $locale === 'en'
                ? "I am sharing this photo: {$detail}."
                : "Te comparto esta foto: {$detail}.";
        }

        return $locale === 'en'
            ? 'I am sharing one of my photos.'
            : 'Te comparto una de mis fotos.';
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function mediaLinkSentence(array $media, string $locale): string
    {
        if (! isset($media['permalink']) || ! is_string($media['permalink']) || trim($media['permalink']) === '') {
            return '';
        }

        $providerLabel = $this->mediaProviderLabel($media);

        if ($providerLabel === null) {
            return $locale === 'en'
                ? 'You can see more photos from the link.'
                : 'Puedes ver más fotos en el enlace.';
        }

        return $locale === 'en'
            ? "You can see more photos on {$providerLabel}."
            : "Puedes ver más fotos en {$providerLabel}.";
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function mediaProviderLabel(array $media): ?string
    {
        foreach (['provider_label', 'provider'] as $key) {
            if (isset($media[$key]) && is_string($media[$key]) && trim($media[$key]) !== '') {
                return trim($media[$key]);
            }
        }

        return null;
    }

    private function profileLocale(Profile $profile): string
    {
        return in_array($profile->locale, ['en', 'es'], true) ? $profile->locale : 'es';
    }

    private function spokenTextForAnswer(string $answerText): string
    {
        return $this->stripRawUrls($answerText);
    }

    private function stripRawUrls(string $text): string
    {
        $text = (string) preg_replace('/https?:\/\/[^\s)]+/u', '', $text);
        $text = (string) preg_replace('/\s*:\s*([.?!]|$)/u', '$1', $text);
        $text = (string) preg_replace('/\s+([.,;:!?])/u', '$1', $text);
        $text = (string) preg_replace('/\s{2,}/u', ' ', $text);

        return trim($text);
    }

    private function cleanMediaText(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = trim((string) $value);
        $text = $this->stripRawUrls($text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    private function extractLocation(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        if (preg_match('/\b(?:en|desde|from|in)\s+([^\.,;:!?\n]+)/iu', $text, $matches) === 1) {
            return $this->normalizeLocation($matches[1] ?? '');
        }

        if (preg_match('/^\p{Lu}[\p{L}\p{M}\'-]*(?:\s+\p{Lu}[\p{L}\p{M}\'-]*){0,3}$/u', $text) === 1) {
            return $this->normalizeLocation($text);
        }

        return null;
    }

    private function normalizeLocation(string $location): ?string
    {
        $location = trim((string) preg_replace('/\b(?:mostrar|show|ver|see)\b.*$/iu', '', $location));
        $location = trim($location, " \t\n\r\0\x0B.,;:-");

        if (mb_strtolower($location) === 'mexico') {
            $location = 'México';
        }

        return $location !== '' ? $location : null;
    }

    private function shortenText(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(0, $limit - 1))).'...';
    }

    private function answerIndicatesNoAnswer(string $answerText): bool
    {
        $normalized = mb_strtolower($answerText);

        return str_contains($answerText, '[[BIGMELO_NO_ANSWER]]')
            || str_contains($normalized, 'no tengo esa información')
            || str_contains($normalized, 'no tengo informacion')
            || str_contains($normalized, 'do not have that information')
            || str_contains($normalized, "don't have that information");
    }

    private function normalizeMarkdownMediaSyntax(string $answerText): string
    {
        $withoutImages = (string) preg_replace('/!\[[^\]]*]\([^)]+\)\s*/u', '', $answerText);

        $withoutLinks = (string) preg_replace('/\[([^\]]+)]\((https?:\/\/[^)]+)\)/u', '$1', $withoutImages);
        $withoutBold = (string) preg_replace('/(\*\*|__)(.*?)\1/u', '$2', $withoutLinks);

        return trim((string) preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '$1', $withoutBold));
    }
}
