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

        $answerText = $this->conversationMessages()->stripNoAnswerMarker($chatAIAnswer->answer);
        $source = $chatAIAnswer->source;
        $audioPayload = null;
        $usesPreconfiguredAnswer = false;

        if ($this->conversationMessages()->shouldUseFallbackAnswer($profile, $chatAIAnswer->answer)) {
            $fallback = $this->conversationMessages()->resolvedMessage(
                $profile,
                \App\Models\ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER
            );
            $answerText = (string) $fallback['text'];
            $source = 'profile_conversation_message';
            $audioPayload = $this->preconfiguredAudioPayload($fallback);
            $usesPreconfiguredAnswer = true;
        }

        if ($audioPayload === null && ! $usesPreconfiguredAnswer) {
            $audio = $this->getAudio($profile, $answerText);
            $audioUrl = $audio?->getAudioUrl();

            $audioPayload = $audio ? [
                'audio_url' => $audioUrl,
                'status' => $audio->status,
                'metadata' => $audio->metadata,
            ] : null;
        }

        $audioUrl = $audioPayload['audio_url'] ?? null;

        $answerMessage = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $question->chat_id,
            'text' => $answerText,
            'type' => 'answer',
            'source' => $source,
            'audio' => $audioUrl,
            'data' => [
                'chat_ai' => $chatAIAnswer->toArray(),
                'audio' => $audioPayload,
            ],
        ]);

        return new AnswerResponse($answerMessage, $chatAIAnswer, $audioPayload);
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
}
