<?php

namespace App\Classes\ChatAIService;

use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Classes\VoiceService\VoiceManager;
use App\Classes\VoiceService\VoiceService;
use App\Exceptions\ChatAIService\ChatAIAnswerGenerationFailed;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\Message;
use App\Models\Profile;
use App\Models\ProfileConversationMessage;
use App\Models\ProfileIntegration;
use App\Models\User;
use App\Models\Voice;
use App\Services\Insights\ProfileInteractionRecorder;
use App\Services\Integrations\ProfileMediaPromptService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Products\ProfileProductPromptService;
use App\Services\ProfileConversationMessageService;
use App\Services\ProfileKnowledge\ProfileKnowledgeQueryIntentAnalyzer;
use App\Services\ProfileVoiceSettings;
use Illuminate\Support\Facades\Log;

class AnswerBuilder
{
    private const MAX_ANSWER_CHARACTERS = 400;

    private const MAX_PRODUCT_ANSWER_CHARACTERS = 400;

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

        if (! $chatAIAnswer->isSuccessful() || ! $chatAIAnswer->hasAnswer()) {
            throw new ChatAIAnswerGenerationFailed($chatAIAnswer);
        }

        $rawAnswerText = $chatAIAnswer->answer;
        $structuredAnswer = $this->parseStructuredAnswer($rawAnswerText);
        $productService = app(ProfileProductPromptService::class);
        $availableProducts = $productService->productsForPrompt($profile);
        $usesKnowledgeRetrieval = data_get($chatAIAnswer->response, '_bigmelo.knowledge.mode') === 'rag';
        $retrievedProductIds = $this->knowledgeSourceIds($chatAIAnswer, 'product');
        $retrievedSocialProviders = $this->knowledgeSourceKeys($chatAIAnswer, 'social_link');
        $queryIntent = app(ProfileKnowledgeQueryIntentAnalyzer::class)->analyze($question->text);

        if ($usesKnowledgeRetrieval) {
            $retrievedProductOrder = array_flip($retrievedProductIds);
            $availableProducts = collect($availableProducts)
                ->filter(fn (array $product): bool => in_array((int) ($product['id'] ?? 0), $retrievedProductIds, true))
                ->sortBy(fn (array $product): int => $retrievedProductOrder[(int) ($product['id'] ?? 0)] ?? PHP_INT_MAX)
                ->values()
                ->all();
        }

        $mediaService = app(ProfileMediaPromptService::class);
        $textMediaContext = $mediaService->analyze(
            $profile,
            $question->text,
            $question->chat_id,
            $question->id
        );
        $validatedStructuredMediaIds = $structuredAnswer !== null
            ? $this->uniqueIntegers([
                ...($structuredAnswer['media_action'] === 'show' ? $structuredAnswer['media_ids'] : []),
                ...$this->structuredReferenceIds($structuredAnswer, 'integration_media'),
            ])
            : [];

        if ($structuredAnswer !== null) {
            $mediaContext = $this->mergeStructuredMediaContextWithText(
                $mediaService,
                $mediaService->analyzeStructuredRequest(
                    $profile,
                    $structuredAnswer,
                    $question->chat_id,
                    $question->id
                ),
                $textMediaContext
            );
        } else {
            $mediaContext = $textMediaContext;
        }
        $answerText = $this->conversationMessages()->stripNoAnswerMarker(
            $structuredAnswer['answer'] ?? $rawAnswerText
        );
        $mediaContext = $this->constrainMediaContextToRequestedSubject(
            $mediaService,
            $mediaContext,
            $question->text,
            $validatedStructuredMediaIds,
        );
        $mediaContext = $this->constrainMediaContextToRequestedType(
            $mediaService,
            $mediaContext,
            $question->text
        );
        $retrievedMediaIds = $this->knowledgeSourceIds($chatAIAnswer, 'integration_media');
        $mediaContext = $this->constrainMediaContextToRetrievedIds(
            $mediaService,
            $mediaContext,
            $retrievedMediaIds,
            $usesKnowledgeRetrieval,
        );
        $hasNoAnswerMarker = str_contains($rawAnswerText, '[[BIGMELO_NO_ANSWER]]');
        $source = $chatAIAnswer->source;
        $audioPayload = null;
        $usesPreconfiguredAnswer = false;
        $structuredMediaIds = $validatedStructuredMediaIds;
        $mediaPayload = $structuredAnswer !== null
            ? $this->mediaPayloadForIds($structuredMediaIds, $mediaContext['candidate_media'])
            : $this->fallbackMediaPayloadForQuestion($profile, $question, $mediaContext);
        $structuredProductIds = $structuredAnswer !== null
            ? $this->uniqueIntegers([
                ...($structuredAnswer['product_action'] === 'show' ? $structuredAnswer['product_ids'] : []),
                ...$this->structuredReferenceIds($structuredAnswer, 'product'),
            ])
            : [];
        $productPayload = $productService->payloadForIds($structuredProductIds, $availableProducts);
        $hasProductRecommendationIntent = $queryIntent->productRecommendation
            || (bool) ($structuredAnswer['product_request'] ?? false);

        if ($productPayload === [] && $hasProductRecommendationIntent) {
            $mentionedProductIds = $this->productIdsMentionedInText($answerText, $availableProducts);
            $productPayload = $productService->payloadForIds($mentionedProductIds, $availableProducts);
            $recoveryReason = 'answer_product_name';

            if ($productPayload === [] && ! $queryIntent->product && $availableProducts !== []) {
                $productPayload = $productService->payloadForIds(
                    [(int) $availableProducts[0]['id']],
                    $availableProducts,
                );
                $recoveryReason = 'retrieved_concrete_need';
            }

            if ($productPayload !== []) {
                Log::info('Recovered product cards from validated retrieval.', [
                    'profile_id' => $profile->id,
                    'chat_id' => $question->chat_id,
                    'question_message_id' => $question->id,
                    'reason' => $recoveryReason,
                    'product_ids' => collect($productPayload)->pluck('id')->all(),
                ]);
            }
        }

        if (
            $productPayload !== []
            && $hasProductRecommendationIntent
            && ($hasNoAnswerMarker || trim($answerText) === '' || $this->answerIndicatesNoAnswer($answerText))
        ) {
            $answerText = $this->productRecommendationFallbackAnswer($productPayload, $profile);
            $source = 'profile_product_rules';
        }
        $mediaPayloadWasReplaced = false;
        $usesMediaRuleAnswer = false;
        $hasExplicitMediaRequest = (bool) ($textMediaContext['wants_media'] ?? false);

        if ($productPayload !== [] && ! $hasExplicitMediaRequest) {
            $mediaPayload = [];
            $mediaContext['wants_media'] = false;
            $mediaContext['candidate_media'] = [];
        }

        if (
            $mediaPayload === []
            && ($mediaContext['wants_media'] ?? false)
            && ($mediaContext['candidate_media'] ?? []) !== []
            && $this->looksLikeExplicitMediaShowRequest($question->text)
        ) {
            $mediaPayload = $this->targetedMediaPayloadForQuestion($question, $mediaContext, $answerText);

            if ($mediaPayload === []) {
                $mediaPayload = [$this->mediaItemToPayload($mediaContext['candidate_media'][0])];
            }

            $mediaPayloadWasReplaced = true;
        }

        if (
            $mediaPayload === []
            && ! ($mediaContext['wants_media'] ?? false)
            && ($mediaContext['candidate_media'] ?? []) !== []
            && $usesKnowledgeRetrieval
            && $this->hasDirectMediaShowIntent($question->text)
        ) {
            $mediaPayload = $this->explicitlyRequestedMediaPayloadUsedInAnswer(
                $question,
                $mediaContext,
                $answerText,
            );

            if ($mediaPayload !== []) {
                $mediaContext['wants_media'] = true;
            }
        }

        if (
            $mediaPayload === []
            && ! ($mediaContext['wants_media'] ?? false)
            && ($mediaContext['candidate_media'] ?? []) !== []
            && config('ai-knowledge.retrieval.proactive_media_enabled', false)
        ) {
            $mediaPayload = $this->stronglyRelevantMediaPayloadForQuestion($question, $mediaContext, $answerText);

            if ($mediaPayload !== []) {
                $mediaContext['wants_media'] = true;
            }
        }

        if ($structuredAnswer !== null && $this->shouldPreferUnseenMedia($question, $mediaPayload)) {
            $unseenMediaPayload = $this->fallbackMediaPayloadForQuestion($profile, $question, $mediaContext);

            if ($unseenMediaPayload !== []) {
                $mediaPayload = $unseenMediaPayload;
                $mediaPayloadWasReplaced = true;
            }
        }

        if (
            $structuredAnswer !== null
            && $mediaPayload === []
            && ($mediaContext['wants_media'] ?? false)
            && ($mediaContext['candidate_media'] ?? []) !== []
            && (
                ($structuredAnswer['media_action'] ?? null) === 'show'
                || $this->answerIndicatesNoAnswer($answerText)
                || $this->looksLikeSpecificMediaShowRequest($question)
                || (
                    ($structuredAnswer['media_request'] ?? false)
                    && $this->hasMediaShowIntent($question->text)
                )
            )
        ) {
            $mediaPayload = $this->targetedMediaPayloadForQuestion($question, $mediaContext, $answerText);
            $mediaPayloadWasReplaced = $mediaPayload !== [];

            if (
                $mediaPayload !== []
                && ($structuredAnswer['media_request'] ?? false)
                && ($structuredAnswer['media_action'] ?? null) === 'none'
            ) {
                Log::info('Recovered relevant profile media after incomplete AI media selection.', [
                    'profile_id' => $profile->id,
                    'chat_id' => $question->chat_id,
                    'question_message_id' => $question->id,
                    'media_ids' => collect($mediaPayload)->pluck('id')->filter()->values()->all(),
                ]);
            }
        }

        if (
            $structuredAnswer !== null
            && $mediaPayload === []
            && ($mediaContext['wants_media'] ?? false)
            && (($structuredAnswer['media_action'] ?? null) === 'show' || $this->answerIndicatesNoAnswer($answerText))
            && (($structuredAnswer['media_action'] ?? null) === 'show' || $this->shouldForceShowAvailableMedia($question, $mediaContext))
            && $this->shouldFallbackAttachMedia($question, $mediaContext)
        ) {
            $mediaPayload = $this->fallbackMediaPayloadForQuestion($profile, $question, $mediaContext);
            $mediaPayloadWasReplaced = $mediaPayload !== [] && $structuredMediaIds !== [];
        }

        if ($mediaContext['wants_media'] && $mediaPayload === [] && $mediaContext['candidate_media'] === []) {
            $invalidStructuredMediaSelection = $structuredAnswer !== null
                && ($structuredAnswer['media_action'] ?? null) === 'show'
                && $structuredMediaIds !== [];

            if ($invalidStructuredMediaSelection || $this->answerIndicatesNoAnswer($answerText) || trim($answerText) === '') {
                $answerText = $this->noMatchingMediaAnswer($mediaContext, $profile);
                $source = 'profile_media_rules';
            }

            $usesMediaRuleAnswer = true;
        }

        if (
            $structuredAnswer !== null
            && ($mediaContext['wants_media'] ?? false)
            && $mediaPayload === []
            && ($mediaContext['candidate_media'] ?? []) !== []
            && $this->answerIndicatesNoAnswer($answerText)
        ) {
            $answerText = $this->hasMediaConstraints($mediaContext) || $this->answerDeclinesOtherMedia($answerText)
                ? $this->noMatchingMediaAnswer($mediaContext, $profile)
                : $this->mediaAvailabilityAnswer($mediaContext, $profile);
            $source = 'profile_media_rules';
            $usesMediaRuleAnswer = true;
        }

        if (
            $structuredAnswer !== null
            && ! ($mediaContext['wants_media'] ?? false)
            && $mediaPayload === []
            && ($mediaContext['candidate_media'] ?? []) !== []
            && ($hasNoAnswerMarker || trim($answerText) === '' || $this->answerIndicatesNoAnswer($answerText))
        ) {
            $factMedia = $this->targetedMediaItem(
                $mediaContext['candidate_media'],
                $question,
                $answerText,
                80,
            );

            if ($factMedia !== null) {
                $answerText = $this->mediaFactFallbackAnswer($factMedia, $profile);
                $source = 'profile_media_fact_rules';
                $usesMediaRuleAnswer = true;
            }
        }

        if (
            $structuredAnswer !== null
            && ($mediaContext['wants_media'] ?? false)
            && $mediaPayload === []
            && $hasNoAnswerMarker
        ) {
            $answerText = $this->noMatchingMediaAnswer($mediaContext, $profile);
            $source = 'profile_media_rules';
            $usesMediaRuleAnswer = true;
        }

        if (
            $mediaPayload !== []
            && (
                $mediaPayloadWasReplaced
                || $hasNoAnswerMarker
                || trim($answerText) === ''
                || $this->answerIndicatesNoAnswer($answerText)
            )
        ) {
            $answerText = $this->mediaFallbackAnswer($mediaPayload, $profile);
        } elseif (! $usesMediaRuleAnswer && $this->conversationMessages()->shouldUseFallbackAnswer(
            $profile,
            $structuredAnswer['answer'] ?? $rawAnswerText
        )) {
            if ($mediaPayload === [] && $productPayload === []) {
                $fallback = $this->conversationMessages()->resolvedMessage(
                    $profile,
                    ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER
                );
                $answerText = (string) $fallback['text'];
                $source = 'profile_conversation_message';
                $audioPayload = $this->shouldUseResponseAudio($profile, $question)
                    ? $this->preconfiguredAudioPayload($fallback)
                    : null;
                $usesPreconfiguredAnswer = true;
            }
        }

        $answerText = $this->normalizeAgeRestrictedMediaAnswer($answerText, $mediaPayload);
        $socialLinkPayload = $this->socialLinkPayload(
            $profile,
            $question->text,
            $answerText,
            $mediaPayload,
            $structuredAnswer,
            $queryIntent->providers,
            $retrievedSocialProviders,
            $usesKnowledgeRetrieval,
        );

        if (
            $socialLinkPayload !== []
            && $queryIntent->socialLink
            && $this->answerIndicatesNoAnswer($answerText)
        ) {
            $answerText = $this->socialLinkFallbackAnswer($socialLinkPayload, $profile);
            $source = 'profile_social_link_rules';
        }

        $displayAnswerText = $this->limitAnswerText(
            $this->appendMediaHint($answerText, $mediaPayload, $profile),
            $productPayload === [] ? self::MAX_ANSWER_CHARACTERS : self::MAX_PRODUCT_ANSWER_CHARACTERS
        );
        $audioText = $this->spokenTextForAnswer($displayAnswerText);

        if ($audioPayload === null && ! $usesPreconfiguredAnswer && $this->shouldUseResponseAudio($profile, $question)) {
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
                'products' => $productPayload,
                'social_links' => $socialLinkPayload,
            ],
        ]);

        $interactionRecorder = app(ProfileInteractionRecorder::class);
        $interactionRecorder->recordShownMedia($profile, $answerMessage, $mediaPayload);
        $interactionRecorder->recordShownProducts($profile, $answerMessage, $productPayload);

        return new AnswerResponse(
            $answerMessage,
            $chatAIAnswer,
            $audioPayload,
            $mediaPayload,
            $productPayload,
            $socialLinkPayload
        );
    }

    /**
     * RAG exposes the authoritative records selected during retrieval. Keep
     * media fallback deterministic by using only those records and preserving
     * their ranking order.
     *
     * @param  array<int, int>  $retrievedIds
     * @return array<string, mixed>
     */
    private function constrainMediaContextToRetrievedIds(
        ProfileMediaPromptService $mediaService,
        array $mediaContext,
        array $retrievedIds,
        bool $usesRetrieval,
    ): array {
        if (! $usesRetrieval) {
            return $mediaContext;
        }

        $byId = collect($mediaContext['candidate_media'] ?? [])
            ->keyBy(fn (array $media): int => (int) ($media['id'] ?? 0));
        $candidateMedia = collect($retrievedIds)
            ->map(fn (int $id) => $byId->get($id))
            ->filter(fn ($media): bool => is_array($media))
            ->values()
            ->all();

        return array_merge($mediaContext, [
            'candidate_media' => $candidateMedia,
            'candidate_provider_labels' => $mediaService->providerLabels($candidateMedia),
            'retrieved_media_ids' => $retrievedIds,
        ]);
    }

    /**
     * @param  array<string, mixed>  $structuredContext
     * @param  array<string, mixed>  $textContext
     * @return array<string, mixed>
     */
    private function mergeStructuredMediaContextWithText(
        ProfileMediaPromptService $mediaService,
        array $structuredContext,
        array $textContext
    ): array {
        $structuredConstraints = $mediaService->normalizeConstraints($structuredContext['constraints'] ?? []);
        $textConstraints = $mediaService->normalizeConstraints($textContext['constraints'] ?? []);
        $excludedProviders = $this->uniqueStrings([
            ...$structuredConstraints['exclude_providers'],
            ...$textConstraints['exclude_providers'],
        ]);
        $excludedSourceTypes = $this->uniqueStrings([
            ...$structuredConstraints['exclude_source_types'],
            ...$textConstraints['exclude_source_types'],
        ]);
        $includedProviders = array_values(array_diff($this->uniqueStrings([
            ...$structuredConstraints['include_providers'],
            ...$textConstraints['include_providers'],
        ]), $excludedProviders));
        $includedProviderSourceTypes = $this->uniqueStrings(array_map(
            fn (string $provider): string => $mediaService->sourceTypeForProvider($provider),
            $includedProviders
        ));
        $excludedSourceTypes = array_values(array_diff($excludedSourceTypes, $includedProviderSourceTypes));
        $includedSourceTypes = array_values(array_diff($this->uniqueStrings([
            ...$structuredConstraints['include_source_types'],
            ...$textConstraints['include_source_types'],
        ]), $excludedSourceTypes));

        $constraints = [
            'include_providers' => $includedProviders,
            'exclude_providers' => $excludedProviders,
            'include_source_types' => $includedSourceTypes,
            'exclude_source_types' => $excludedSourceTypes,
            'require_unseen' => (bool) $structuredConstraints['require_unseen'] || (bool) $textConstraints['require_unseen'],
        ];
        $availableMedia = is_array($structuredContext['available_media'] ?? null)
            ? $structuredContext['available_media']
            : ($textContext['available_media'] ?? []);
        $shownMediaIds = $this->uniqueIntegers([
            ...($structuredContext['shown_media_ids'] ?? []),
            ...($textContext['shown_media_ids'] ?? []),
        ]);
        $candidateMedia = $mediaService->candidateMediaForConstraints($availableMedia, $constraints, $shownMediaIds);

        return array_merge($structuredContext, [
            'wants_media' => (bool) ($structuredContext['wants_media'] ?? false) || (bool) ($textContext['wants_media'] ?? false),
            'use_unseen' => (bool) $constraints['require_unseen'],
            'constraints' => $constraints,
            'included_providers' => $includedProviders,
            'excluded_providers' => $excludedProviders,
            'included_source_types' => $includedSourceTypes,
            'excluded_source_types' => $excludedSourceTypes,
            'shown_media_ids' => $shownMediaIds,
            'available_media' => $availableMedia,
            'candidate_media' => $candidateMedia,
            'available_provider_labels' => $mediaService->providerLabels($availableMedia),
            'candidate_provider_labels' => $mediaService->providerLabels($candidateMedia),
        ]);
    }

    /**
     * @param  array<string, mixed>  $mediaContext
     * @return array<string, mixed>
     */
    private function constrainMediaContextToRequestedSubject(
        ProfileMediaPromptService $mediaService,
        array $mediaContext,
        string $question,
        array $validatedStructuredMediaIds = [],
    ): array {
        if (($mediaContext['included_providers'] ?? []) === []) {
            return $mediaContext;
        }

        $subjectTerms = $this->mediaRequestSubjectTerms($question);

        if ($subjectTerms === []) {
            return $mediaContext;
        }

        $candidateMedia = collect($mediaContext['candidate_media'] ?? [])
            ->filter(fn (array $media): bool => in_array((int) ($media['id'] ?? 0), $validatedStructuredMediaIds, true)
                || $this->mediaMatchesRequestedSubject($media, $subjectTerms))
            ->values()
            ->all();

        return array_merge($mediaContext, [
            'candidate_media' => $candidateMedia,
            'candidate_provider_labels' => $mediaService->providerLabels($candidateMedia),
        ]);
    }

    /**
     * @param  array<string, mixed>  $mediaContext
     * @return array<string, mixed>
     */
    private function constrainMediaContextToRequestedType(
        ProfileMediaPromptService $mediaService,
        array $mediaContext,
        string $question
    ): array {
        $requestedType = $this->requestedMediaType($question);

        if ($requestedType === null) {
            return $mediaContext;
        }

        $candidateMedia = collect($mediaContext['candidate_media'] ?? [])
            ->filter(function (array $media) use ($requestedType): bool {
                $isVideo = str_contains(mb_strtoupper((string) ($media['media_type'] ?? '')), 'VIDEO');

                return $requestedType === 'VIDEO' ? $isVideo : ! $isVideo;
            })
            ->values()
            ->all();

        return array_merge($mediaContext, [
            'candidate_media' => $candidateMedia,
            'candidate_provider_labels' => $mediaService->providerLabels($candidateMedia),
            'requested_media_type' => $requestedType,
        ]);
    }

    private function requestedMediaType(string $question): ?string
    {
        $normalized = $this->normalizeSearchText($question);
        $asksForImage = preg_match('/\b(foto|fotos|imagen|imagenes|infografia|infografias|photo|photos|picture|pictures|image|images|infographic|infographics)\b/u', $normalized) === 1;
        $asksForVideo = preg_match('/\b(video|videos|clip|clips)\b/u', $normalized) === 1;

        if ($asksForImage === $asksForVideo) {
            return null;
        }

        return $asksForVideo ? 'VIDEO' : 'IMAGE';
    }

    /**
     * @return array<int, string>
     */
    private function mediaRequestSubjectTerms(string $question): array
    {
        $ignored = [
            'algo',
            'anything',
            'available',
            'clip',
            'clips',
            'comparte',
            'comparteme',
            'contenido',
            'contenidos',
            'content',
            'dame',
            'disponible',
            'disponibles',
            'ensename',
            'foto',
            'fotos',
            'have',
            'image',
            'images',
            'imagen',
            'imagenes',
            'infografia',
            'infografias',
            'infographic',
            'infographics',
            'instagram',
            'media',
            'mostrar',
            'muestra',
            'muestrame',
            'onlyfans',
            'photo',
            'photos',
            'picture',
            'pictures',
            'post',
            'posts',
            'publicacion',
            'publicaciones',
            'publication',
            'publications',
            'profile',
            'perfil',
            'puede',
            'puedes',
            'quiero',
            'send',
            'share',
            'show',
            'tenga',
            'tengan',
            'tienes',
            'tiktok',
            'video',
            'videos',
            'want',
            'what',
            'youtube',
        ];

        return collect([
            ...$this->searchTokens($question),
            ...$this->shortReferenceTokens($question),
        ])
            ->filter(fn (string $term): bool => ! in_array($term, $ignored, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $media
     * @param  array<int, string>  $subjectTerms
     */
    private function mediaMatchesRequestedSubject(array $media, array $subjectTerms): bool
    {
        $mediaTerms = $this->mediaSearchTerms($media);

        foreach ($subjectTerms as $subjectTerm) {
            foreach ($mediaTerms as $mediaTerm) {
                if (
                    $subjectTerm === $mediaTerm
                    || mb_strpos($mediaTerm, $subjectTerm) !== false
                    || mb_strpos($subjectTerm, $mediaTerm) !== false
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function uniqueStrings(array $values): array
    {
        return collect($values)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private function uniqueIntegers(array $values): array
    {
        return collect($values)
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
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
        } catch (SubscriptionEntitlementException $e) {
            Log::info('Audio response skipped because the TTS quota is unavailable.', [
                'code' => $e->errorCode(),
                'profile_id' => $profile->id,
                'voice_id' => $voice->id,
            ]);

            return null;
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

    private function shouldUseResponseAudio(Profile $profile, Message $question): bool
    {
        return app(ProfileVoiceSettings::class)->shouldGenerateResponseAudio($profile, $question);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackMediaPayloadForQuestion(Profile $profile, Message $question, array $mediaContext): array
    {
        if (! $this->shouldFallbackAttachMedia($question, $mediaContext)) {
            return [];
        }

        $media = $mediaContext['candidate_media'] ?? [];

        if ($media === []) {
            return [];
        }

        $item = $this->fallbackMediaItem($media, $question);

        return $item !== null ? [$this->mediaItemToPayload($item)] : [];
    }

    /**
     * @param  array<string, mixed>  $mediaContext
     * @return array<int, array<string, mixed>>
     */
    private function targetedMediaPayloadForQuestion(Message $question, array $mediaContext, string $answerText): array
    {
        $media = $mediaContext['candidate_media'] ?? [];

        if ($media === []) {
            return [];
        }

        $item = $this->targetedMediaItem($media, $question, $answerText);

        return $item !== null ? [$this->mediaItemToPayload($item)] : [];
    }

    /**
     * Recommend selected integration media when its admin-provided observation
     * is a strong match for the visitor's question and the generated answer.
     *
     * @param  array<string, mixed>  $mediaContext
     * @return array<int, array<string, mixed>>
     */
    private function stronglyRelevantMediaPayloadForQuestion(
        Message $question,
        array $mediaContext,
        string $answerText
    ): array {
        $candidateMedia = $mediaContext['candidate_media'] ?? [];

        if ($candidateMedia === []) {
            return [];
        }

        $item = $this->targetedMediaItem($candidateMedia, $question, $answerText, 130);

        return $item !== null ? [$this->mediaItemToPayload($item)] : [];
    }

    /**
     * Recover an omitted media reference only after a direct show/share verb
     * and an exact factual term connects the question, answer, and retrieved item.
     *
     * @param  array<string, mixed>  $mediaContext
     * @return array<int, array<string, mixed>>
     */
    private function explicitlyRequestedMediaPayloadUsedInAnswer(
        Message $question,
        array $mediaContext,
        string $answerText
    ): array {
        $questionText = $this->normalizeSearchText($question->text);
        $normalizedAnswer = $this->normalizeSearchText($answerText);

        if ($normalizedAnswer === '') {
            return [];
        }

        $candidates = collect($mediaContext['candidate_media'] ?? [])
            ->filter(function (array $media) use ($questionText, $normalizedAnswer): bool {
                return collect($this->mediaSearchTerms($media))->contains(
                    fn (string $term): bool => mb_strpos($questionText, $term) !== false
                        && mb_strpos($normalizedAnswer, $term) !== false
                );
            })
            ->values()
            ->all();

        if ($candidates === []) {
            return [];
        }

        $item = $this->targetedMediaItem($candidates, $question, $answerText, 130);

        return $item !== null ? [$this->mediaItemToPayload($item)] : [];
    }

    /**
     * @param  array<int|string>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function mediaPayloadForIds(array $ids, array $candidateMedia): array
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

        if ($candidateMedia === []) {
            return [];
        }

        $mediaById = collect($candidateMedia)->keyBy(fn (array $item): int => (int) ($item['id'] ?? 0));

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
            'type' => 'integration_media',
            'provider' => $item['provider_label'] ?? $item['provider'] ?? 'Instagram',
            'provider_key' => $item['provider_key'] ?? 'instagram',
            'provider_label' => $item['provider_label'] ?? $item['provider'] ?? 'Instagram',
            'source_type' => $item['source_type'] ?? 'integration',
            'media_type' => $item['media_type'] ?? null,
            'image_url' => $item['image_url'] ?? null,
            'media_url' => $item['media_url'] ?? null,
            'thumbnail_url' => $item['thumbnail_url'] ?? null,
            'permalink' => $item['permalink'] ?? null,
            'caption' => $item['caption'] ?? null,
            'observation' => $item['observation'] ?? null,
            'age_restricted' => (bool) ($item['age_restricted'] ?? false),
            'taken_at' => $item['taken_at'] ?? null,
            'channel_url' => $item['channel_url'] ?? null,
            'destination_type' => $item['destination_type'] ?? null,
            'destination_label' => $item['destination_label'] ?? null,
            'action_type' => $item['action_type'] ?? null,
            'action_label' => $item['action_label'] ?? null,
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

    /**
     * @param  array<int, array<string, mixed>>  $media
     * @return array<string, mixed>|null
     */
    private function targetedMediaItem(
        array $media,
        Message $question,
        string $answerText,
        int $minimumScore = 30
    ): ?array {
        $questionText = $this->normalizeSearchText($question->text);
        $answerText = $this->normalizeSearchText($answerText);
        $bestItem = null;
        $bestScore = 0;

        foreach ($media as $item) {
            $score = 0;

            foreach ($this->mediaSearchTerms($item) as $term) {
                $score += $this->searchTermScore($questionText, $term, 80);
                $score += $this->searchTermScore($answerText, $term, 30);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestItem = $item;
            }
        }

        return $bestScore >= $minimumScore ? $bestItem : null;
    }

    private function shouldForceShowAvailableMedia(Message $question, array $mediaContext): bool
    {
        if (($mediaContext['candidate_media'] ?? []) === []) {
            return false;
        }

        $text = $question->text;

        return $this->looksLikeExplicitMediaShowRequest($text)
            || $this->looksLikeMediaAvailabilityRequest($text, $mediaContext)
            || ($this->looksLikeAnyMediaChoice($text) && $this->recentConversationMentionsMedia($question))
            || ($this->looksLikeAnotherMediaRequest($text) && $this->recentShownMediaIds($question) !== []);
    }

    private function looksLikeMediaAvailabilityRequest(string $text, array $mediaContext): bool
    {
        if (($mediaContext['included_providers'] ?? []) === []) {
            return false;
        }

        $normalized = mb_strtolower($text);

        return preg_match(
            '/\b(qu[eé]\b[^.?!]{0,60}\btienes|tienes\s+(?:algo|contenido)|algo\s+de|what\s+do\s+you\s+have|anything\s+(?:from|on))\b/u',
            $normalized
        ) === 1;
    }

    private function looksLikeExplicitMediaShowRequest(string $text): bool
    {
        return $this->hasMediaShowIntent($text) && ($this->looksLikeMediaRequest($text) || $this->looksLikeAnyMediaChoice($text));
    }

    private function looksLikeSpecificMediaShowRequest(Message $question): bool
    {
        return $this->hasMediaShowIntent($question->text)
            && ($this->recentConversationMentionsMedia($question) || $this->looksLikeMediaRequest($question->text));
    }

    private function hasMediaShowIntent(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return preg_match('/\b(muestra|mu[eé]strame|muestres|mostrar|ens[eé][ñn]ame|ver|ve|quiero|show|see|view)\b/u', $normalized) === 1;
    }

    private function hasDirectMediaShowIntent(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return preg_match(
            '/\b(muestra|mu[eé]strame|muestres|mostrar|ens[eé][ñn]ame|comparte|comp[aá]rteme|env[ií]a|env[ií]ame|show|share|send)\b/u',
            $normalized,
        ) === 1;
    }

    private function looksLikeMediaRequest(string $text): bool
    {
        $normalized = mb_strtolower($text);

        foreach ([
            'foto',
            'fotos',
            'imagen',
            'imágenes',
            'infografía',
            'infografías',
            'infographic',
            'infographics',
            'instagram',
            'post',
            'publicación',
            'video',
            'videos',
            'clip',
            'clips',
            'media',
            'onlyfans',
            'only fans',
            'tiktok',
            'youtube',
            'you tube',
            'photo',
            'picture',
            'image',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function shouldFallbackAttachMedia(Message $question, array $mediaContext): bool
    {
        if ($this->looksLikeMediaReferenceQuestion($question->text)) {
            return false;
        }

        if ($mediaContext['wants_media'] ?? false) {
            return true;
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

        return preg_match('/\b(cualquier|cualquiera|whatever|whichever)\b/u', $normalized) === 1
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
     * @return array{
     *     answer: string,
     *     media_request: bool,
     *     media_action: string|null,
     *     media_ids: array<int>,
     *     product_request: bool,
     *     product_action: string|null,
     *     product_ids: array<int>,
     *     references: array<int, array{type:string,id:string}>,
     *     constraints: array<string, mixed>
     * }|null
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

        $mediaRequest = filter_var($payload['media_request'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $productIds = [];

        if (isset($payload['product_ids']) && is_array($payload['product_ids'])) {
            $productIds = collect($payload['product_ids'])
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        $productAction = is_scalar($payload['product_action'] ?? null)
            ? mb_strtolower((string) $payload['product_action'])
            : null;

        if (! in_array($productAction, ['show', 'none'], true)) {
            $productAction = $productIds !== [] ? 'show' : 'none';
        }

        $productRequest = filter_var($payload['product_request'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $references = collect(is_array($payload['references'] ?? null) ? $payload['references'] : [])
            ->filter(fn ($reference): bool => is_array($reference))
            ->map(function (array $reference): ?array {
                $type = is_scalar($reference['type'] ?? null)
                    ? mb_strtolower(trim((string) $reference['type']))
                    : '';
                $id = is_scalar($reference['id'] ?? null)
                    ? trim((string) $reference['id'])
                    : '';

                if (! in_array($type, ['integration_media', 'product', 'social_link'], true) || $id === '') {
                    return null;
                }

                return compact('type', 'id');
            })
            ->filter()
            ->unique(fn (array $reference): string => $reference['type'].':'.$reference['id'])
            ->values()
            ->all();

        return [
            'answer' => is_scalar($payload['answer']) ? (string) $payload['answer'] : '',
            'media_request' => $mediaRequest ?? ($mediaAction === 'show' || $mediaIds !== []),
            'media_action' => $mediaAction,
            'media_ids' => $mediaIds,
            'product_request' => $productRequest ?? ($productAction === 'show' || $productIds !== []),
            'product_action' => $productAction,
            'product_ids' => $productIds,
            'references' => $references,
            'constraints' => is_array($payload['constraints'] ?? null) ? $payload['constraints'] : [],
        ];
    }

    /** @return array<int, int> */
    private function structuredReferenceIds(array $structuredAnswer, string $type): array
    {
        return collect($structuredAnswer['references'] ?? [])
            ->filter(fn ($reference): bool => is_array($reference) && ($reference['type'] ?? null) === $type)
            ->map(fn (array $reference): int => (int) ($reference['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, int>
     */
    private function productIdsMentionedInText(string $answerText, array $products): array
    {
        $normalizedAnswer = $this->normalizeSearchText($answerText);

        return collect($products)
            ->filter(function (array $product) use ($normalizedAnswer): bool {
                $name = $this->normalizeSearchText((string) ($product['name'] ?? ''));

                return $name !== '' && str_contains($normalizedAnswer, $name);
            })
            ->map(fn (array $product): int => (int) ($product['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
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
    private function normalizeAgeRestrictedMediaAnswer(string $answerText, array $mediaPayload): string
    {
        $hasAgeRestrictedMedia = collect($mediaPayload)
            ->contains(fn (array $media): bool => (bool) ($media['age_restricted'] ?? false));

        if (! $hasAgeRestrictedMedia) {
            return $answerText;
        }

        $answerText = (string) preg_replace(
            '/\s*(?:espero\s+que\s+te\s+guste|que\s+lo\s+disfrutes|disfr[uú]talo|hope\s+you\s+like\s+it|enjoy\s+it)\s*[.!]*/iu',
            '',
            $answerText
        );

        return trim((string) preg_replace('/\s+/u', ' ', $answerText));
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
     * @param  array<int, array<string, mixed>>  $productPayload
     */
    private function productRecommendationFallbackAnswer(array $productPayload, Profile $profile): string
    {
        $names = collect($productPayload)
            ->pluck('name')
            ->filter(fn ($name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => trim($name))
            ->values()
            ->all();
        $products = $this->humanList($names, $this->profileLocale($profile));

        if ($products === '') {
            return $this->profileLocale($profile) === 'en'
                ? 'I found an option that fits what you are looking for. I am showing it here.'
                : 'Encontré una opción que encaja con lo que buscas. Te la muestro aquí.';
        }

        return $this->profileLocale($profile) === 'en'
            ? "An option that fits what you are looking for is {$products}. I am showing it here."
            : "Una opción que encaja con lo que buscas es {$products}. Te la muestro aquí.";
    }

    /** @param array<string, mixed> $media */
    private function mediaFactFallbackAnswer(array $media, Profile $profile): string
    {
        $detail = $this->cleanMediaText($media['observation'] ?? null)
            ?: $this->cleanMediaText($media['caption'] ?? null);

        if ($detail === '') {
            return $this->profileLocale($profile) === 'en'
                ? 'I do not have that detail available right now.'
                : 'No tengo ese detalle disponible por ahora.';
        }

        $detail = rtrim($this->shortenText($detail, 170), " \t\n\r\0\x0B.");

        return $this->profileLocale($profile) === 'en'
            ? "According to the available content, {$detail}."
            : "Según el contenido disponible, {$detail}.";
    }

    /**
     * @param  array<string, mixed>  $mediaContext
     */
    private function mediaAvailabilityAnswer(array $mediaContext, Profile $profile): string
    {
        $locale = $this->profileLocale($profile);
        $providers = $this->humanList($mediaContext['candidate_provider_labels'] ?? [], $locale);
        $locations = $this->mediaLocationLabels($mediaContext['candidate_media'] ?? []);
        $isVideo = ($mediaContext['requested_media_type'] ?? null) === 'VIDEO';
        $mediaName = $locale === 'en'
            ? ($isVideo ? 'videos' : 'photos')
            : ($isVideo ? 'videos' : 'fotos');
        $one = $locale === 'en' ? 'one' : ($isVideo ? 'uno' : 'una');

        if ($locations !== []) {
            $places = $this->humanList($locations, $locale);

            return $locale === 'en'
                ? "I have {$mediaName} from {$providers} in {$places}. I can show you {$one}."
                : "Tengo {$mediaName} de {$providers} en {$places}. Puedo mostrarte {$one}.";
        }

        return $locale === 'en'
            ? "I have {$mediaName} from {$providers}. I can show you {$one}."
            : "Tengo {$mediaName} de {$providers}. Puedo mostrarte {$one}.";
    }

    /**
     * @param  array<string, mixed>  $mediaContext
     */
    private function noMatchingMediaAnswer(array $mediaContext, Profile $profile): string
    {
        $locale = $this->profileLocale($profile);
        $availableLabels = $mediaContext['available_provider_labels'] ?? [];
        $includedProviders = $mediaContext['included_providers'] ?? [];
        $excludedProviders = $mediaContext['excluded_providers'] ?? [];
        $excludedSourceTypes = $mediaContext['excluded_source_types'] ?? [];
        $useUnseen = (bool) ($mediaContext['use_unseen'] ?? false);
        $isVideo = ($mediaContext['requested_media_type'] ?? null) === 'VIDEO';
        $mediaName = $locale === 'en'
            ? ($isVideo ? 'videos' : 'photos')
            : ($isVideo ? 'videos' : 'fotos');

        if ($useUnseen) {
            return $locale === 'en'
                ? "I do not have more {$mediaName} available right now."
                : "No tengo más {$mediaName} disponibles por ahora.";
        }

        if (is_array($includedProviders) && $includedProviders !== []) {
            $requestedLabels = collect($includedProviders)
                ->map(fn (string $provider): string => app(ProfileMediaPromptService::class)->providerLabel($provider))
                ->values()
                ->all();
            $providers = $this->humanList($requestedLabels, $locale);

            if (in_array(ProfileIntegration::PROVIDER_ONLYFANS, $includedProviders, true)) {
                return $locale === 'en'
                    ? "I do not have matching content available on {$providers} right now."
                    : "No tengo contenido coincidente disponible en {$providers} por ahora.";
            }

            return $locale === 'en'
                ? "I do not have {$mediaName} available on {$providers} right now."
                : "No tengo {$mediaName} disponibles en {$providers} por ahora.";
        }

        if (
            (
                (is_array($excludedProviders) && $excludedProviders !== [])
                || (is_array($excludedSourceTypes) && $excludedSourceTypes !== [])
            )
            && is_array($availableLabels)
            && $availableLabels !== []
        ) {
            $providers = $this->humanList($availableLabels, $locale);

            return $locale === 'en'
                ? "Right now I only have {$mediaName} from {$providers}."
                : "Por ahora solo tengo {$mediaName} de {$providers}.";
        }

        if (is_array($availableLabels) && $availableLabels !== []) {
            $providers = $this->humanList($availableLabels, $locale);

            return $locale === 'en'
                ? "Right now I only have {$mediaName} from {$providers}."
                : "Por ahora solo tengo {$mediaName} de {$providers}.";
        }

        return $locale === 'en'
            ? "I do not have {$mediaName} available right now."
            : "No tengo {$mediaName} disponibles por ahora.";
    }

    /**
     * @param  array<string, mixed>  $mediaContext
     */
    private function hasMediaConstraints(array $mediaContext): bool
    {
        return ($mediaContext['included_providers'] ?? []) !== []
            || ($mediaContext['excluded_providers'] ?? []) !== []
            || ($mediaContext['included_source_types'] ?? []) !== []
            || ($mediaContext['excluded_source_types'] ?? []) !== []
            || (bool) ($mediaContext['use_unseen'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function mediaContextSentence(array $media, string $locale): string
    {
        $observation = $this->cleanMediaText($media['observation'] ?? null);
        $caption = $this->cleanMediaText($media['caption'] ?? null);
        $isYouTube = ($media['provider_key'] ?? null) === ProfileIntegration::PROVIDER_YOUTUBE;
        $location = $isYouTube ? null : $this->extractLocation($observation);
        $isVideo = $this->isVideoMedia($media);

        if (! $isYouTube && $location === null && $observation === '') {
            $location = $this->extractLocation($caption);
        }

        if ($location !== null) {
            if ($isVideo) {
                return $locale === 'en'
                    ? "This video was recorded in {$location}."
                    : "Este video fue grabado en {$location}.";
            }

            return $locale === 'en'
                ? "This photo was taken in {$location}."
                : "Esta foto fue tomada en {$location}.";
        }

        $detail = $observation ?: $caption;

        if ($detail !== '') {
            $detail = rtrim($this->shortenText($detail, 140), " \t\n\r\0\x0B.");

            if ($isVideo) {
                return $locale === 'en'
                    ? "I am sharing this video: {$detail}."
                    : "Te comparto este video: {$detail}.";
            }

            return $locale === 'en'
                ? "I am sharing this photo: {$detail}."
                : "Te comparto esta foto: {$detail}.";
        }

        if ($isVideo) {
            return $locale === 'en'
                ? 'I am sharing one of my videos.'
                : 'Te comparto uno de mis videos.';
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
        if (($media['provider_key'] ?? null) === ProfileIntegration::PROVIDER_ONLYFANS) {
            return '';
        }

        if (! isset($media['permalink']) || ! is_string($media['permalink']) || trim($media['permalink']) === '') {
            return '';
        }

        if (
            ($media['provider_key'] ?? null) === ProfileIntegration::PROVIDER_OTHER
            && is_string($media['action_label'] ?? null)
            && trim($media['action_label']) !== ''
        ) {
            $actionLabel = trim($media['action_label']);
            $actionLabel = mb_strtolower(mb_substr($actionLabel, 0, 1)).mb_substr($actionLabel, 1);

            return $locale === 'en'
                ? "You can {$actionLabel}."
                : "Puedes {$actionLabel}.";
        }

        $providerLabel = $this->mediaProviderLabel($media);
        $isVideo = $this->isVideoMedia($media);

        if ($providerLabel === null) {
            if ($isVideo) {
                return $locale === 'en'
                    ? 'You can watch it from the link.'
                    : 'Puedes verlo en el enlace.';
            }

            return $locale === 'en'
                ? 'You can see more photos from the link.'
                : 'Puedes ver más fotos en el enlace.';
        }

        if ($isVideo) {
            return $locale === 'en'
                ? "You can watch it on {$providerLabel}."
                : "Puedes verlo en {$providerLabel}.";
        }

        return $locale === 'en'
            ? "You can see more photos on {$providerLabel}."
            : "Puedes ver más fotos en {$providerLabel}.";
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function isVideoMedia(array $media): bool
    {
        $mediaType = strtoupper(trim((string) ($media['media_type'] ?? '')));

        return str_contains($mediaType, 'VIDEO');
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

    /**
     * Build chat CTA buttons only for configured social links discussed in the
     * current answer. Media cards with the same destination already own the CTA.
     *
     * @param  array<int, array<string, mixed>>  $mediaPayload
     * @param  array<int, string>  $intentProviders
     * @param  array<int, string>  $retrievedProviders
     * @return array<int, array<string, string>>
     */
    private function socialLinkPayload(
        Profile $profile,
        string $questionText,
        string $answerText,
        array $mediaPayload,
        ?array $structuredAnswer,
        array $intentProviders,
        array $retrievedProviders,
        bool $usesRetrieval,
    ): array {
        $networks = (array) ($profile->networks ?? []);

        if ($networks === []) {
            return [];
        }

        $genericSocialRequest = $this->looksLikeGenericSocialLinkRequest($questionText)
            && ! $this->looksLikeMediaRequest($questionText);
        $mediaDestinations = collect($mediaPayload)
            ->filter(fn (array $media): bool => filled($media['permalink'] ?? null) || filled($media['channel_url'] ?? null))
            ->flatMap(fn (array $media): array => [
                $this->normalizeSocialProvider((string) ($media['provider_key'] ?? '')),
                $this->normalizeSocialProvider((string) ($media['destination_type'] ?? '')),
            ])
            ->filter()
            ->unique()
            ->all();
        $locale = $this->profileLocale($profile);
        $structuredProviders = collect($structuredAnswer['references'] ?? [])
            ->filter(fn ($reference): bool => is_array($reference) && ($reference['type'] ?? null) === 'social_link')
            ->map(fn (array $reference): string => $this->normalizeSocialProvider((string) ($reference['id'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $intentProviders = collect($intentProviders)
            ->map(fn (string $provider): string => $this->normalizeSocialProvider($provider))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $retrievedProviders = collect($retrievedProviders)
            ->map(fn (string $provider): string => $this->normalizeSocialProvider($provider))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $requestedProviders = collect([...$structuredProviders, ...$intentProviders])
            ->unique()
            ->when(
                $usesRetrieval,
                fn ($providers) => $providers->filter(fn (string $provider): bool => in_array($provider, $retrievedProviders, true))
            )
            ->values()
            ->all();
        $links = [];

        foreach ($networks as $provider => $url) {
            if (! is_scalar($url)) {
                continue;
            }

            $provider = $this->normalizeSocialProvider((string) $provider);
            $url = trim((string) $url);

            if ($provider === '' || ! $this->isSafeSocialUrl($url)) {
                continue;
            }

            if (in_array($provider, $mediaDestinations, true)) {
                continue;
            }

            if ($usesRetrieval && ! in_array($provider, $retrievedProviders, true)) {
                continue;
            }

            $label = $this->socialNetworkLabel($provider);
            $answerMentionsUrl = $this->answerMentionsSocialUrl($answerText, $url);
            $answerMentionsProvider = $this->textMentionsSocialProvider($answerText, $provider, $label);
            $questionMentionsProvider = $this->textMentionsSocialProvider($questionText, $provider, $label);

            $isRequestedProvider = in_array($provider, $requestedProviders, true);

            if (
                ! $genericSocialRequest
                && ! $isRequestedProvider
                && ! $answerMentionsUrl
                && ! ($answerMentionsProvider && $questionMentionsProvider)
            ) {
                continue;
            }

            $links[] = [
                'provider_key' => $provider,
                'provider_label' => $label,
                'action_label' => $locale === 'en' ? "Go to {$label}" : "Ir a {$label}",
                'url' => $url,
            ];
        }

        return $links;
    }

    /**
     * @param  array<int, array<string, string>>  $links
     */
    private function socialLinkFallbackAnswer(array $links, Profile $profile): string
    {
        $locale = $this->profileLocale($profile);
        $labels = collect($links)
            ->pluck('provider_label')
            ->filter(fn ($label): bool => is_string($label) && trim($label) !== '')
            ->values()
            ->all();

        if (count($labels) === 1) {
            $label = $labels[0];

            return $locale === 'en'
                ? "You can find my official {$label} profile here."
                : "Puedes encontrar mi perfil oficial de {$label} aquí.";
        }

        return $locale === 'en'
            ? 'You can find my official social profiles here.'
            : 'Puedes encontrar mis perfiles oficiales en redes sociales aquí.';
    }

    /** @return array<int, int> */
    private function knowledgeSourceIds(ChatAIAnswer $answer, string $sourceType): array
    {
        $mode = data_get($answer->response, '_bigmelo.knowledge.mode');

        if ($mode !== 'rag') {
            return [];
        }

        return collect(data_get($answer->response, '_bigmelo.knowledge.retrieved_sources', []))
            ->filter(fn ($source): bool => is_array($source) && ($source['source_type'] ?? null) === $sourceType)
            ->map(fn (array $source): int => (int) ($source['source_id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function knowledgeSourceKeys(ChatAIAnswer $answer, string $sourceType): array
    {
        $mode = data_get($answer->response, '_bigmelo.knowledge.mode');

        if ($mode !== 'rag') {
            return [];
        }

        return collect(data_get($answer->response, '_bigmelo.knowledge.retrieved_sources', []))
            ->filter(fn ($source): bool => is_array($source) && ($source['source_type'] ?? null) === $sourceType)
            ->map(fn (array $source): string => trim((string) ($source['source_id'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function looksLikeGenericSocialLinkRequest(string $text): bool
    {
        $normalized = $this->normalizeSearchText($text);

        return preg_match(
            '/\b(redes?\s+sociales|tus\s+redes|donde\s+(?:puedo\s+)?seguirte|social\s+(?:media|networks?)|your\s+socials|where\s+can\s+i\s+follow)\b/u',
            $normalized
        ) === 1;
    }

    private function textMentionsSocialProvider(string $text, string $provider, string $label): bool
    {
        $normalizedText = $this->normalizeSearchText($text);
        $aliases = array_filter([
            $provider,
            $label,
            ...match ($provider) {
                'x' => ['twitter', 'x.com'],
                'github' => ['git hub'],
                'linkedin' => ['linked in'],
                'tiktok' => ['tik tok'],
                'youtube' => ['you tube'],
                'onlyfans' => ['only fans'],
                default => [],
            },
        ]);

        foreach ($aliases as $alias) {
            $normalizedAlias = $this->normalizeSearchText((string) $alias);

            if ($normalizedAlias === '') {
                continue;
            }

            $pattern = str_replace('\\ ', '\\s+', preg_quote($normalizedAlias, '/'));

            if (preg_match('/(?<![\pL\pN])'.$pattern.'(?![\pL\pN])/u', $normalizedText) === 1) {
                return true;
            }
        }

        return false;
    }

    private function answerMentionsSocialUrl(string $answerText, string $configuredUrl): bool
    {
        $normalizedConfiguredUrl = rtrim($configuredUrl, '/');

        if ($normalizedConfiguredUrl === '') {
            return false;
        }

        return str_contains(rtrim($answerText, '/'), $normalizedConfiguredUrl);
    }

    private function normalizeSocialProvider(string $provider): string
    {
        return trim(mb_strtolower(str_replace([' ', '-'], '_', $provider)), '_');
    }

    private function socialNetworkLabel(string $provider): string
    {
        $definition = config("social-networks.networks.{$provider}", []);

        if (is_array($definition) && filled($definition['name'] ?? null)) {
            return trim((string) $definition['name']);
        }

        return $provider === 'x' ? 'X' : ucwords(str_replace('_', ' ', $provider));
    }

    private function isSafeSocialUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
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

    /**
     * @param  array<int, array<string, mixed>>  $media
     * @return array<int, string>
     */
    private function mediaLocationLabels(array $media): array
    {
        return collect($media)
            ->map(function (array $item): ?string {
                $observation = $this->cleanMediaText($item['observation'] ?? null);
                $caption = $this->cleanMediaText($item['caption'] ?? null);

                return $this->extractLocation($observation) ?? $this->extractLocation($caption);
            })
            ->filter()
            ->unique(fn (string $location): string => $this->normalizeSearchText($location))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $media
     * @return array<int, string>
     */
    private function mediaSearchTerms(array $media): array
    {
        $texts = array_filter([
            $this->cleanMediaText($media['observation'] ?? null),
            $this->cleanMediaText($media['caption'] ?? null),
        ]);

        $terms = [];

        foreach ($texts as $text) {
            $location = $this->extractLocation($text);

            if ($location !== null) {
                $terms[] = $location;
            }

            $terms = array_merge($terms, $this->searchTokens($text), $this->shortReferenceTokens($text));
        }

        return collect($terms)
            ->map(fn (string $term): string => $this->normalizeSearchText($term))
            ->filter(fn (string $term): bool => $term !== '' && ! in_array($term, $this->ignoredMediaSearchTerms(), true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function searchTokens(string $text): array
    {
        $normalized = $this->normalizeSearchText($text);
        preg_match_all('/[\pL\pN]{4,}/u', $normalized, $matches);

        return $matches[0] ?? [];
    }

    /** @return array<int, string> */
    private function shortReferenceTokens(string $text): array
    {
        preg_match_all('/[\pL\pN]+/u', $text, $matches);

        return collect($matches[0] ?? [])
            ->filter(function (string $token): bool {
                $length = mb_strlen($token);

                if ($length < 2 || $length > 3) {
                    return false;
                }

                return preg_match('/\d/u', $token) === 1 || mb_strtoupper($token) === $token;
            })
            ->map(fn (string $token): string => $this->normalizeSearchText($token))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeSearchText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            'á' => 'a',
            'à' => 'a',
            'ä' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ë' => 'e',
            'ê' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'ï' => 'i',
            'î' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ö' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'ü' => 'u',
            'û' => 'u',
            'ñ' => 'n',
            'ç' => 'c',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function searchTermScore(string $haystack, string $term, int $weight): int
    {
        if ($haystack === '' || $term === '') {
            return 0;
        }

        $position = mb_strpos($haystack, $term);

        if ($position === false) {
            return 0;
        }

        return $weight + max(0, 20 - (int) floor($position / 10));
    }

    /**
     * @return array<int, string>
     */
    private function ignoredMediaSearchTerms(): array
    {
        return [
            'caption',
            'como',
            'contenido',
            'enlace',
            'experiencias',
            'foto',
            'fotos',
            'image',
            'images',
            'instagram',
            'link',
            'mostrar',
            'para',
            'photo',
            'photos',
            'publicacion',
            'publicaciones',
            'publication',
            'publications',
            'preguntan',
            'social',
            'sobre',
            'tengo',
            'tiene',
            'tienes',
            'video',
            'videos',
            'youtube',
        ];
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

    private function limitAnswerText(string $text, int $limit): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $truncated = rtrim(mb_substr($text, 0, max(0, $limit - 3)));
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace >= (int) floor($limit * 0.65)) {
            $truncated = rtrim(mb_substr($truncated, 0, $lastSpace));
        }

        return rtrim($truncated, " \t\n\r\0\x0B.,;:").'...';
    }

    /**
     * @param  array<int, string>  $items
     */
    private function humanList(array $items, string $locale): string
    {
        $items = array_values(array_filter(array_map(
            fn (string $item): string => trim($item),
            $items
        )));

        if ($items === []) {
            return '';
        }

        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);
        $joiner = $locale === 'en' ? ' and ' : ' y ';

        return implode(', ', $items).$joiner.$last;
    }

    private function answerIndicatesNoAnswer(string $answerText): bool
    {
        $normalized = mb_strtolower($answerText);
        $declinesMedia = preg_match(
            '/\bno\s+(?:tengo|hay)\b[^.?!]{0,100}\b(foto|fotos|imagen|im[aá]genes|video|videos|contenido|media|photo|photos|image|images|content)\b/u',
            $normalized
        ) === 1;

        return str_contains($answerText, '[[BIGMELO_NO_ANSWER]]')
            || $declinesMedia
            || str_contains($normalized, 'no tengo esa información')
            || str_contains($normalized, 'no tengo informacion')
            || str_contains($normalized, 'no puedo responder')
            || str_contains($normalized, 'no hay fotos disponibles')
            || str_contains($normalized, 'no tengo otras fotos')
            || str_contains($normalized, 'no tengo más fotos')
            || str_contains($normalized, 'no tengo mas fotos')
            || str_contains($normalized, 'no matching photo')
            || str_contains($normalized, 'no other photos')
            || str_contains($normalized, 'no more photos')
            || str_contains($normalized, 'pregúntame otra cosa')
            || str_contains($normalized, 'preguntame otra cosa')
            || str_contains($normalized, 'do not have that information')
            || str_contains($normalized, "don't have that information")
            || str_contains($normalized, 'cannot answer')
            || str_contains($normalized, "can't answer")
            || str_contains($normalized, 'ask me something else');
    }

    private function answerDeclinesOtherMedia(string $answerText): bool
    {
        $normalized = mb_strtolower($answerText);

        return preg_match('/\b(?:otra|otras|otro|otros|other|more)\b[^.?!]{0,60}\b(foto|fotos|imagen|im[aá]genes|photo|photos|image|images)\b/u', $normalized) === 1;
    }

    private function normalizeMarkdownMediaSyntax(string $answerText): string
    {
        $withoutImages = (string) preg_replace('/!\[[^\]]*]\([^)]+\)\s*/u', '', $answerText);

        $withoutLinks = (string) preg_replace('/\[([^\]]+)]\((https?:\/\/[^)]+)\)/u', '$1', $withoutImages);
        $withoutBold = (string) preg_replace('/(\*\*|__)(.*?)\1/u', '$2', $withoutLinks);

        return trim((string) preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '$1', $withoutBold));
    }
}
