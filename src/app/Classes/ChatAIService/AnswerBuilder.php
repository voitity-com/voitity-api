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
        $structuredAnswer = $this->parseStructuredAnswer($rawAnswerText);
        $answerText = $this->conversationMessages()->stripNoAnswerMarker(
            $structuredAnswer['answer'] ?? $rawAnswerText
        );
        $source = $chatAIAnswer->source;
        $audioPayload = null;
        $usesPreconfiguredAnswer = false;
        $structuredMediaIds = $structuredAnswer !== null && $structuredAnswer['media_action'] === 'show'
            ? $structuredAnswer['media_ids']
            : [];
        $mediaPayload = $structuredAnswer !== null
            ? $this->mediaPayloadForIds($profile, $structuredMediaIds)
            : $this->fallbackMediaPayloadForQuestion($profile, $question);
        $mediaPayloadWasReplaced = false;

        if ($structuredAnswer !== null && $mediaPayload === [] && $this->shouldFallbackAttachMedia($question)) {
            $mediaPayload = $this->fallbackMediaPayloadForQuestion($profile, $question);
        }

        if ($structuredAnswer !== null && $this->shouldPreferUnseenMedia($question, $mediaPayload)) {
            $fallbackMediaPayload = $this->fallbackMediaPayloadForQuestion($profile, $question);

            if ($fallbackMediaPayload !== []) {
                $mediaPayload = $fallbackMediaPayload;
                $mediaPayloadWasReplaced = true;
            }
        }

        if ($mediaPayload !== [] && ($mediaPayloadWasReplaced || $this->answerIndicatesNoAnswer($answerText))) {
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
    private function fallbackMediaPayloadForQuestion(Profile $profile, Message $question): array
    {
        if (! $this->shouldFallbackAttachMedia($question)) {
            return [];
        }

        $media = app(InstagramIntegrationService::class)->selectedMediaForPrompt($profile);

        if ($media === []) {
            return [];
        }

        $item = $this->fallbackMediaItem($media, $question);

        return $item !== null ? [$this->mediaItemToPayload($item)] : [];
    }

    /**
     * @param  array<int|string>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function mediaPayloadForIds(Profile $profile, array $ids): array
    {
        $ids = collect($ids)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $media = app(InstagramIntegrationService::class)->selectedMediaForPrompt($profile);

        if ($media === []) {
            return [];
        }

        $mediaById = collect($media)->keyBy(fn (array $item): int => (int) ($item['id'] ?? 0));

        return collect($ids)
            ->map(fn (int $id) => $mediaById->get($id))
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => $this->mediaItemToPayload($item))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mediaItemToPayload(array $item): array
    {
        return [
            'id' => $item['id'] ?? null,
            'type' => 'instagram_media',
            'provider' => $item['provider_label'] ?? $item['provider'] ?? 'Instagram',
            'provider_key' => $item['provider_key'] ?? 'instagram',
            'provider_label' => $item['provider_label'] ?? $item['provider'] ?? 'Instagram',
            'image_url' => $item['image_url'] ?? null,
            'permalink' => $item['permalink'] ?? null,
            'caption' => $item['caption'] ?? null,
            'observation' => $item['observation'] ?? null,
            'taken_at' => $item['taken_at'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     * @return array<string, mixed>|null
     */
    private function fallbackMediaItem(array $media, Message $question): ?array
    {
        if ($this->looksLikeAnotherMediaRequest($question->text) || $this->looksLikeAnyMediaChoice($question->text)) {
            $shownIds = $this->recentShownMediaIds($question);

            foreach ($media as $item) {
                $itemId = (int) ($item['id'] ?? 0);

                if ($itemId > 0 && ! in_array($itemId, $shownIds, true)) {
                    return $item;
                }
            }

            return null;
        }

        return $media[0] ?? null;
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

    private function shouldFallbackAttachMedia(Message $question): bool
    {
        if ($this->looksLikeMediaReferenceQuestion($question->text)) {
            return false;
        }

        if ($this->looksLikeMediaRequest($question->text)) {
            return true;
        }

        return ($this->looksLikeAnotherMediaRequest($question->text)
            && $this->recentShownMediaIds($question) !== [])
            || ($this->looksLikeAnyMediaChoice($question->text)
                && $this->recentConversationMentionsMedia($question));
    }

    private function looksLikeAnotherMediaRequest(string $text): bool
    {
        $normalized = mb_strtolower($text);
        $asksAnother = preg_match('/\b(otra|otro|otras|otros|another|more|m[aá]s)\b/u', $normalized) === 1;
        $hasShowIntent = preg_match('/\b(muestra|mu[eé]strame|ens[eé][ñn]ame|ver|ve|quiero|show|see|view)\b/u', $normalized) === 1;
        $isShortFollowUp = preg_match('/^\s*(otra|otro|another|more|m[aá]s)\s*[?!.]*\s*$/u', $normalized) === 1;

        return $asksAnother && ($hasShowIntent || $isShortFollowUp || $this->looksLikeMediaRequest($text));
    }

    private function looksLikeAnyMediaChoice(string $text): bool
    {
        $normalized = trim(mb_strtolower($text));

        if (mb_strlen($normalized) > 48) {
            return false;
        }

        return preg_match('/\b(cualquiera|whatever|whichever)\b/u', $normalized) === 1
            || preg_match('/\b(la|el|una|uno)\s+que\s+quieras\b/u', $normalized) === 1
            || preg_match('/\b(any|anyone|anything)\b/u', $normalized) === 1;
    }

    private function looksLikeMediaReferenceQuestion(string $text): bool
    {
        $normalized = mb_strtolower($text);

        $hasReference = preg_match('/\b(esa|ese|esta|este|that|this|anterior|previous|last)\b/u', $normalized) === 1;
        $asksContext = preg_match('/\b(d[oó]nde|donde|where|cu[aá]ndo|cuando|when|qu[eé]|que|what)\b/u', $normalized) === 1;

        return $hasReference && $asksContext;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mediaPayload
     */
    private function shouldPreferUnseenMedia(Message $question, array $mediaPayload): bool
    {
        if ($mediaPayload === []) {
            return false;
        }

        if (! $this->looksLikeAnotherMediaRequest($question->text) && ! $this->looksLikeAnyMediaChoice($question->text)) {
            return false;
        }

        $shownIds = $this->recentShownMediaIds($question);

        if ($shownIds === []) {
            return false;
        }

        foreach ($mediaPayload as $media) {
            $mediaId = (int) ($media['id'] ?? 0);

            if ($mediaId <= 0 || ! in_array($mediaId, $shownIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int>
     */
    private function recentShownMediaIds(Message $question): array
    {
        if (! $question->chat_id) {
            return [];
        }

        return Message::query()
            ->where('chat_id', $question->chat_id)
            ->where('type', 'answer')
            ->where('id', '<', $question->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['data'])
            ->flatMap(function (Message $message): array {
                $media = $message->data['media'] ?? [];

                if (! is_array($media)) {
                    return [];
                }

                return collect($media)
                    ->map(fn ($item): int => is_array($item) ? (int) ($item['id'] ?? 0) : 0)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->all();
            })
            ->unique()
            ->values()
            ->all();
    }

    private function recentConversationMentionsMedia(Message $question): bool
    {
        if (! $question->chat_id) {
            return false;
        }

        return Message::query()
            ->where('chat_id', $question->chat_id)
            ->where('id', '<', $question->id)
            ->orderByDesc('id')
            ->limit(6)
            ->pluck('text')
            ->contains(fn ($text): bool => is_string($text) && $this->looksLikeMediaRequest($text));
    }

    /**
     * @return array{answer: string, media_action: string|null, media_ids: array<int>}|null
     */
    private function parseStructuredAnswer(string $answer): ?array
    {
        $trimmed = trim($answer);

        if ($trimmed === '') {
            return null;
        }

        $jsonText = $this->extractJsonObject($trimmed);

        if ($jsonText === null) {
            return null;
        }

        $payload = json_decode($jsonText, true);

        if (! is_array($payload) || ! array_key_exists('answer', $payload)) {
            return null;
        }

        $mediaIds = [];

        if (isset($payload['media_ids']) && is_array($payload['media_ids'])) {
            $mediaIds = collect($payload['media_ids'])
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        $mediaAction = is_scalar($payload['media_action'] ?? null)
            ? mb_strtolower((string) $payload['media_action'])
            : null;

        if (! in_array($mediaAction, ['show', 'none'], true)) {
            $mediaAction = $mediaIds !== [] ? 'show' : 'none';
        }

        return [
            'answer' => is_scalar($payload['answer']) ? (string) $payload['answer'] : '',
            'media_action' => $mediaAction,
            'media_ids' => $mediaIds,
        ];
    }

    private function extractJsonObject(string $text): ?string
    {
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = (string) preg_replace('/\s*```$/', '', $text);
            $text = trim($text);
        }

        if (str_starts_with($text, '{') && str_ends_with($text, '}')) {
            return $text;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($text, $start, $end - $start + 1);
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
        $providerPattern = $providerLabel !== null ? preg_quote(mb_strtolower($providerLabel), '/') : null;
        $providerLinkMentioned = $providerPattern !== null
            && preg_match('/(?:\b(ver(?:la|lo|las|los)?|ve|mirar|mira|see|view|watch|look)\b[^.?!]{0,80}\b'.$providerPattern.'\b|\b'.$providerPattern.'\b[^.?!]{0,80}\b(ver(?:la|lo|las|los)?|ve|mirar|mira|see|view|watch|look)\b)/u', $normalizedAnswer) === 1;

        if (($providerMentioned && ($morePhotosMentioned || $providerLinkMentioned)) || str_contains($normalizedAnswer, $normalizedHint)) {
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
            || str_contains($normalized, 'no puedo responder')
            || str_contains($normalized, 'pregúntame otra cosa')
            || str_contains($normalized, 'preguntame otra cosa')
            || str_contains($normalized, 'do not have that information')
            || str_contains($normalized, "don't have that information")
            || str_contains($normalized, 'cannot answer')
            || str_contains($normalized, "can't answer")
            || str_contains($normalized, 'ask me something else');
    }

    private function normalizeMarkdownMediaSyntax(string $answerText): string
    {
        $withoutImages = (string) preg_replace('/!\[[^\]]*]\([^)]+\)\s*/u', '', $answerText);

        $withoutLinks = (string) preg_replace('/\[([^\]]+)]\((https?:\/\/[^)]+)\)/u', '$1', $withoutImages);
        $withoutBold = (string) preg_replace('/(\*\*|__)(.*?)\1/u', '$2', $withoutLinks);

        return trim((string) preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '$1', $withoutBold));
    }
}
