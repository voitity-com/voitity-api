<?php

namespace App\Classes\ChatAIService;

use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Classes\VoiceService\VoiceManager;
use App\Classes\VoiceService\VoiceService;
use App\Enums\SubscriptionUsageType;
use App\Events\Subscriptions\SubscriptionUsageRequested;
use App\Models\Message;
use App\Models\Profile;
use App\Models\Voice;
use Illuminate\Support\Facades\Log;

class AnswerBuilder
{
    public function __construct(
        private readonly ChatAIClient $chatAIClient,
        private readonly VoiceManager $voiceManager
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

        $audio = $this->getAudio($profile, $chatAIAnswer->answer);

        $audioUrl = $audio?->getAudioUrl();

        $audioPayload = $audio ? [
            'audio_url' => $audioUrl,
            'status' => $audio->status,
            'metadata' => $audio->metadata,
        ] : null;

        $answerMessage = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $question->chat_id,
            'text' => $chatAIAnswer->answer,
            'type' => 'answer',
            'source' => $chatAIAnswer->source,
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
        /** @var Voice|null $voice */
        $voice = $profile->voices()->where('active', true)->first();

        if (! $voice) {
            return null;
        }

        try {
            $driverName = $voice->source ?: null;
            $voiceClient = $this->voiceManager->driver($driverName);
        } catch (\Throwable $e) {
            Log::warning('Unable to resolve voice driver for profile.', [
                'profile_id' => $profile->id,
                'voice_id' => $voice->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        try {
            $voiceService = new VoiceService($voice, $voiceClient);

            return $voiceService->generateAudio($text);
        } catch (\Throwable $e) {
            Log::warning('Audio generation failed.', [
                'profile_id' => $profile->id,
                'voice_id' => $voice->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
