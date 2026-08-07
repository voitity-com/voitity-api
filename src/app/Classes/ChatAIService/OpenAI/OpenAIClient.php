<?php

namespace App\Classes\ChatAIService\OpenAI;

use App\Classes\ChatAIService\ChatAIAnswer;
use App\Classes\ChatAIService\ChatAIClient;
use App\Classes\ChatAIService\ChatAITextFromAudio;
use App\Models\Profile;
use App\Services\Integrations\ProfileMediaPromptService;
use App\Services\Products\ProfileProductPromptService;
use App\Services\ProfileKnowledge\ProfileKnowledgePromptContext;
use App\Services\ProfileKnowledge\ProfileKnowledgePromptContextService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIClient implements ChatAIClient
{
    /**
     * The OpenAI API key.
     *
     * @var string
     */
    private $apiKey;

    /**
     * The OpenAI API base URL.
     *
     * @var string
     */
    private $baseUrl;

    /**
     * The default model for chat completions.
     *
     * @var string
     */
    private $defaultModel;

    /**
     * The default model for audio transcriptions.
     *
     * @var string
     */
    private $whisperModel;

    private int $retryAttempts;

    private int $retryDelayMs;

    /**
     * Create a new OpenAIClient instance.
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $defaultModel = null,
        ?string $whisperModel = null,
        ?int $retryAttempts = null,
        ?int $retryDelayMs = null
    ) {
        $this->apiKey = $apiKey ?: config('services.openai.api_key');
        $this->baseUrl = $baseUrl ?: 'https://api.openai.com/v1';
        $this->defaultModel = $defaultModel ?: 'gpt-4';
        $this->whisperModel = $whisperModel ?: 'whisper-1';
        $this->retryAttempts = max(1, $retryAttempts ?? 1);
        $this->retryDelayMs = max(0, $retryDelayMs ?? 0);
    }

    /**
     * Get an AI answer based on a profile and message.
     *
     * @param  Profile  $profile  The user profile for context
     * @param  string  $message  The message to get an answer for
     * @param  int|null  $chatId  The chat ID used to load recent conversation context
     * @param  int|null  $currentMessageId  The current message ID to exclude from recent context
     * @return ChatAIAnswer The AI answer response
     */
    public function getAnswer(Profile $profile, string $message, ?int $chatId = null, ?int $currentMessageId = null): ChatAIAnswer
    {
        $requestUrl = $this->baseUrl.'/chat/completions';

        try {
            $knowledgeContext = app(ProfileKnowledgePromptContextService::class)
                ->build($profile, $message, $chatId, $currentMessageId);
            $systemPrompt = $this->buildSystemPrompt(
                $profile,
                $message,
                $chatId,
                $currentMessageId,
                $knowledgeContext,
            );

            $response = $this->postJsonWithRetry($requestUrl, [
                'model' => $this->defaultModel,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => $message,
                    ],
                ],
                'max_tokens' => 1000,
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ]);

            $responseData = $response->json();

            if (is_array($responseData)) {
                $responseData['_bigmelo']['knowledge'] = $knowledgeContext->metadata();
            }

            if ($response->successful() && isset($responseData['choices'][0]['message']['content'])) {
                $answer = $responseData['choices'][0]['message']['content'];
                $confidence = $this->calculateConfidence($responseData);

                return new ChatAIAnswer(
                    source: 'openai',
                    answer: $answer,
                    status: 'success',
                    requestUrl: $requestUrl,
                    response: $responseData,
                    confidence: $confidence
                );
            } else {
                Log::error('OpenAI API error', [
                    'status' => $response->status(),
                    'response' => $responseData,
                    'request_url' => $requestUrl,
                ]);

                return new ChatAIAnswer(
                    source: 'openai',
                    answer: '',
                    status: 'failed',
                    requestUrl: $requestUrl,
                    response: $responseData
                );
            }
        } catch (\Exception $e) {
            Log::error('OpenAI API exception', [
                'message' => $e->getMessage(),
                'request_url' => $requestUrl,
            ]);

            return new ChatAIAnswer(
                source: 'openai',
                answer: '',
                status: 'error',
                requestUrl: $requestUrl,
                response: ['error' => $e->getMessage()]
            );
        }
    }

    private function postJsonWithRetry(string $requestUrl, array $payload): Response
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->retryAttempts; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ])->post($requestUrl, $payload);

                if (! $this->shouldRetryResponse($response) || $attempt === $this->retryAttempts) {
                    return $response;
                }

                Log::warning('OpenAI API transient response, retrying.', [
                    'attempt' => $attempt,
                    'max_attempts' => $this->retryAttempts,
                    'status' => $response->status(),
                    'request_url' => $requestUrl,
                ]);
            } catch (\Throwable $e) {
                $lastException = $e;

                Log::warning('OpenAI API request attempt failed, retrying if possible.', [
                    'attempt' => $attempt,
                    'max_attempts' => $this->retryAttempts,
                    'message' => $e->getMessage(),
                    'request_url' => $requestUrl,
                ]);

                if ($attempt === $this->retryAttempts) {
                    throw $e;
                }
            }

            $this->waitBeforeRetry();
        }

        throw $lastException ?? new \RuntimeException('OpenAI API request failed.');
    }

    private function shouldRetryResponse(Response $response): bool
    {
        return in_array($response->status(), [408, 429], true) || $response->serverError();
    }

    private function waitBeforeRetry(): void
    {
        if ($this->retryDelayMs <= 0) {
            return;
        }

        usleep($this->retryDelayMs * 1000);
    }

    /**
     * Convert audio to text based on audio file path.
     *
     * @param  string  $audioPath  The path to the audio file
     * @return ChatAITextFromAudio The text extracted from audio
     */
    public function getTextFromAudio(string $audioPath): ChatAITextFromAudio
    {
        $requestUrl = $this->baseUrl.'/audio/transcriptions';

        try {
            if (! file_exists($audioPath)) {
                return new ChatAITextFromAudio(
                    source: 'openai',
                    audioPath: $audioPath,
                    text: '',
                    status: 'failed',
                    response: ['error' => 'Audio file not found']
                );
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->attach(
                'file',
                file_get_contents($audioPath),
                basename($audioPath)
            )->post($requestUrl, [
                'model' => $this->whisperModel,
                'response_format' => 'verbose_json',
            ]);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['text'])) {
                $text = $responseData['text'];
                $confidence = $this->calculateTranscriptionConfidence($responseData);
                $detectedLanguage = $responseData['language'] ?? null;
                $duration = $responseData['duration'] ?? null;

                return new ChatAITextFromAudio(
                    source: 'openai',
                    audioPath: $audioPath,
                    text: $text,
                    status: 'success',
                    response: $responseData,
                    requestUrl: $requestUrl,
                    confidence: $confidence,
                    detectedLanguage: $detectedLanguage,
                    duration: $duration
                );
            } else {
                Log::error('OpenAI Whisper API error', [
                    'status' => $response->status(),
                    'response' => $responseData,
                    'request_url' => $requestUrl,
                    'audio_path' => $audioPath,
                ]);

                return new ChatAITextFromAudio(
                    source: 'openai',
                    audioPath: $audioPath,
                    text: '',
                    status: 'failed',
                    response: $responseData,
                    requestUrl: $requestUrl
                );
            }
        } catch (\Exception $e) {
            Log::error('OpenAI Whisper API exception', [
                'message' => $e->getMessage(),
                'request_url' => $requestUrl,
                'audio_path' => $audioPath,
            ]);

            return new ChatAITextFromAudio(
                source: 'openai',
                audioPath: $audioPath,
                text: '',
                status: 'error',
                response: ['error' => $e->getMessage()],
                requestUrl: $requestUrl
            );
        }
    }

    /**
     * Build a system prompt based on profile data.
     */
    private function buildSystemPrompt(
        Profile $profile,
        string $message,
        ?int $chatId,
        ?int $currentMessageId,
        ProfileKnowledgePromptContext $knowledgeContext,
    ): string {
        $prompt = $profile->name
            ? "Your name is: {$profile->name}"
            : 'You are an AI assistant';

        if ($profile->description) {
            $prompt .= ". {$profile->description}";
        }

        // Add genre context
        if ($profile->genre) {
            $prompt .= " Your gender is {$profile->genre}.";
        }

        // Add personality traits
        if ($profile->personality) {
            $prompt .= " Your personality is {$profile->personality}.";
        }

        $profileLocale = $this->normalizeProfileLocale($profile->locale ?? null);
        $profileLanguage = $profileLocale === 'en' ? 'English' : 'Spanish';
        $prompt .= ". Profile language: {$profileLanguage} ({$profileLocale}). Always answer in {$profileLanguage}, regardless of the visitor's language.";

        $retrievedKnowledge = $this->buildRetrievedKnowledgePrompt($knowledgeContext);

        if ($retrievedKnowledge !== null) {
            $prompt .= ". Retrieved profile knowledge relevant to this question: {$retrievedKnowledge}";
        }

        $publicSocialLinks = $this->buildPublicSocialLinksPrompt($profile, $knowledgeContext);

        if ($publicSocialLinks !== null) {
            $prompt .= ". Public social links (authoritative): {$publicSocialLinks}";
            $prompt .= '. If asked for social networks, usernames, Instagram, GitHub, LinkedIn, or where to see more content, answer using these exact links. Treat these links as available profile information and do not say you do not have them';
        }

        $recentMessages = $this->getRecentChatMessages($profile, $chatId, $currentMessageId);
        $mediaService = app(ProfileMediaPromptService::class);
        $mediaAnalysis = $mediaService->analyze($profile, $message, $chatId, $currentMessageId);
        $availableMedia = ($mediaAnalysis['wants_media'] ?? false)
            && $this->hasMediaRetrievalConstraints($mediaAnalysis)
                ? $mediaAnalysis['candidate_media']
                : $mediaAnalysis['available_media'];

        $mediaIds = array_map('intval', $knowledgeContext->retrieval->sourceIds('integration_media'));
        $availableMedia = collect($availableMedia)
            ->filter(fn (array $media): bool => in_array((int) ($media['id'] ?? 0), $mediaIds, true))
            ->values()
            ->all();

        $selectedMedia = $this->buildSelectedMediaPrompt($availableMedia);
        $productService = app(ProfileProductPromptService::class);
        $availableProducts = $productService->productsForPrompt($profile);

        $productIds = array_map('intval', $knowledgeContext->retrieval->sourceIds('product'));
        $availableProducts = collect($availableProducts)
            ->filter(fn (array $product): bool => in_array((int) ($product['id'] ?? 0), $productIds, true))
            ->values()
            ->all();

        $productsPrompt = $availableProducts !== []
            ? json_encode($availableProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        $productRecommendationGuidance = is_string($productsPrompt)
            && $knowledgeContext->retrieval->hasSourceType('product_guidance')
            ? $productService->recommendationGuidanceForPrompt($profile)
            : null;

        if ($selectedMedia !== null) {
            $prompt .= ". Selected media available for visitor conversations before constraints: {$selectedMedia}";
            $prompt .= '. The observation of every selected media item is also recommendation-routing guidance. If the current question clearly matches an observation, choose the relevant media id and set media_request to true and media_action to "show" even when the visitor did not explicitly ask for media';
            $prompt .= '. Infer from the current message and recent chat whether the visitor is asking for media. When media is requested, set media_request to true and fill constraints from the meaning of the request, not from a fixed phrase list. Constraint keys are include_providers, exclude_providers, include_source_types, exclude_source_types, and require_unseen. Provider values must use provider_key values from the selected media list. Source type values must use source_type values from the selected media list. The social_network source_type means media from social platforms; if the visitor asks for media outside or inside a kind of source, express that through source type constraints. When the visitor names a provider_key or provider_label from the selected media list, use provider constraints for that named provider, not source type constraints. Do not convert a provider exclusion into a source type exclusion; excluding one provider still allows other providers with the same source_type. Apply constraints before choosing media_ids. If at least one selected media item matches the inferred constraints and the visitor wants to see media, choose one matching id and set media_action to "show"; do not answer that no media is available. When media_action is "show", phrase the answer as sharing or attaching the media in the current chat; do not tell the visitor that they need to visit the provider profile or a link to see it. If no selected media matches the inferred constraints, set media_action to "none", media_ids to [], and answer briefly that no matching media is available. For follow-up references to a previously shown media item, answer using the most recently shown media item and keep media_action as "none" unless the visitor asks to show another media item. Use provider_label, observation, caption, and date as factual context. Mention where the media was captured only when observation or caption reasonably indicates a place. For media marked age_restricted, describe it factually as adult promotional content and never invent, intensify, or add sexual details beyond its caption and observation. Keep the tone neutral and do not add invitations such as "enjoy it" or "I hope you like it". Keep media answers short. Do not include raw URLs, Markdown links, Markdown formatting, or Markdown image syntax because the app attaches media and external links separately. Do not invent media, places, providers, source types, or links outside the selected media list';
        }

        if (is_string($productsPrompt)) {
            $prompt .= ". Published products available for this conversation: {$productsPrompt}";
            $prompt .= '. Recommend products organically only when the visitor asks about products, purchasing, recommendations, or when a product is directly relevant to the requested professional guidance. Do not force a sale into unrelated conversation. Use only the exact product names and descriptions provided. Do not invent ingredients, prices, discounts, availability, dimensions, quantities, health outcomes, medical claims, or implied benefits such as improving performance, recovery, strength, sleep, or appearance unless that exact information is present in the product name or description. When the visitor asks to compare products, infer the comparison from explicit values and specifications already present in their names or descriptions, including prices, measurements, units, formats, and quantities. You may normalize compatible units to compare them. State which is cheaper, more expensive, larger, smaller, or otherwise preferable only when the supplied data supports that conclusion; otherwise say that the available descriptions do not establish it. For comparisons of three or more products, use one compact semicolon-separated sentence that includes every compared product and finishes completely within the 400-character product answer limit. Never return a comparison that ends mid-sentence or needs truncation. Phrase a product as an available option that may complement the visitor\'s plan, never as a guaranteed result or personalized medical recommendation. When one or more products are genuinely relevant, set product_request to true, product_action to "show", and product_ids to the relevant published product ids. Prefer the smallest useful set, normally one or two products, but attach every directly compared product when answering a comparison. The app attaches product cards, so do not include raw product URLs or Markdown links in the answer';

            if ($productRecommendationGuidance !== null) {
                $encodedGuidance = json_encode(
                    $productRecommendationGuidance,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                $prompt .= ". Profile-specific product recommendation guidance (routing only, not product facts): {$encodedGuidance}";
                $prompt .= '. Use this guidance only to recognize indirect situations where offering product help is appropriate. It never overrides the published product names, descriptions, safety rules, or factual restrictions. If the visitor only mentions a broad interest or need covered by the guidance but has not asked to see, buy, compare, or receive a product recommendation, offer help naturally and keep product_action as "none" with an empty product_ids array. If the visitor accepts that offer or explicitly requests a recommendation, show the smallest relevant set of published products. Direct product mentions and explicit product requests keep the existing immediate recommendation behavior';
            }
        }

        if ($recentMessages !== []) {
            $recentMessagesJson = json_encode($recentMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($recentMessagesJson !== false) {
                $prompt .= ". Recent chat messages from this chat, oldest to newest: {$recentMessagesJson}. Use them only as conversation context and do not repeat them unless useful";
            }
        }

        $recentlyShownInstagramMedia = $this->buildRecentlyShownMediaPrompt($profile, $recentMessages);

        if ($recentlyShownInstagramMedia !== null) {
            $prompt .= ". Most recently shown media item in this chat: {$recentlyShownInstagramMedia}. For references like \"esa foto\", \"ese video\", \"esa imagen\", \"that photo\", \"that video\", or \"where was that photo\", use this media item first";
        }

        $prompt .= '. Only answer using the information in this prompt. For non-media requests, if the requested information is not available here, start the answer exactly with [[BIGMELO_NO_ANSWER]] and then say you do not have that information at this moment';
        $prompt .= '. A media request with no selected media matching the inferred constraints is answerable from the selected media inventory. For that case, do not use [[BIGMELO_NO_ANSWER]]; set media_request to true, media_action to "none", media_ids to [], keep the inferred constraints, and answer that no matching media is available';
        $prompt .= '. A product request with matching published products is answerable from the product inventory. In that case do not use [[BIGMELO_NO_ANSWER]]. If products are disabled or no published product matches, use product_action "none" and never invent a product';
        $prompt .= '. Make the conversation feel natural and progressive. Evaluate each question and decide whether a short or detailed answer is appropriate. For greetings or questions like who you are, answer briefly with your name and what you do. For broad experience questions, summarize the relevant experience. For questions about a specific experience, expand only that experience. Do not reveal all profile information at once unless the user explicitly asks for a full overview';
        $prompt .= '. Always respond in character and maintain consistency with your defined role and personality.';
        $prompt .= '. The answer string must be 200 characters or fewer, except when product_action is "show"; product answers may use up to 400 characters.';
        $prompt .= '. Return a JSON object only, without surrounding text. The JSON object must have exactly these keys: "answer" as the natural-language answer string, "media_request" as a boolean, "media_action" as "none" or "show", "media_ids" as an array of selected media ids to attach, "product_request" as a boolean, "product_action" as "none" or "show", "product_ids" as an array of published product ids to attach, and "constraints" as an object with include_providers, exclude_providers, include_source_types, exclude_source_types arrays and require_unseen boolean. Use "media_action":"none" and an empty media_ids array when no media should be attached. Use "product_action":"none" and an empty product_ids array when no product should be attached.';

        return $prompt;
    }

    private function buildPublicSocialLinksPrompt(
        Profile $profile,
        ProfileKnowledgePromptContext $knowledgeContext,
    ): ?string {
        $networks = (array) ($profile->networks ?? []);
        $links = [];
        $retrievedNetworks = $knowledgeContext->retrieval->sourceIds('social_link');

        foreach ($networks as $network => $url) {
            if (! in_array((string) $network, $retrievedNetworks, true)) {
                continue;
            }

            if (! is_scalar($url)) {
                continue;
            }

            $url = trim((string) $url);

            if ($url === '') {
                continue;
            }

            $links[] = $this->socialNetworkName((string) $network).': '.$url;
        }

        if ($links === []) {
            return null;
        }

        return implode('; ', $links);
    }

    private function buildRetrievedKnowledgePrompt(ProfileKnowledgePromptContext $context): ?string
    {
        $items = collect($context->retrieval->items)
            ->reject(fn (array $item): bool => in_array($item['source_type'], [
                'integration_media',
                'product',
                'product_guidance',
                'social_link',
                'profile_identity',
            ], true))
            ->map(fn (array $item): array => [
                'source_type' => $item['source_type'],
                'source_id' => $item['source_id'],
                'content' => $item['content'],
            ])
            ->values()
            ->all();

        if ($items === []) {
            return null;
        }

        $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     */
    private function buildSelectedMediaPrompt(array $media): ?string
    {
        if ($media === []) {
            return null;
        }

        $items = collect($media)->map(fn (array $item): array => [
            'id' => $item['id'] ?? null,
            'provider_key' => $item['provider_key'] ?? null,
            'provider_label' => $item['provider_label'] ?? null,
            'source_type' => $item['source_type'] ?? null,
            'type' => $item['media_type'] ?? null,
            'caption' => $item['caption'] ?? null,
            'observation' => $item['observation'] ?? null,
            'age_restricted' => $item['age_restricted'] ?? false,
            'permalink' => $item['permalink'] ?? null,
            'image_url' => $item['image_url'] ?? null,
            'date' => $item['taken_at'] ?? null,
        ])->values()->all();
        $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : null;
    }

    /** @param array<string, mixed> $analysis */
    private function hasMediaRetrievalConstraints(array $analysis): bool
    {
        return collect([
            ...($analysis['included_providers'] ?? []),
            ...($analysis['excluded_providers'] ?? []),
            ...($analysis['included_source_types'] ?? []),
            ...($analysis['excluded_source_types'] ?? []),
        ])->isNotEmpty();
    }

    private function normalizeProfileLocale(?string $locale): string
    {
        return in_array($locale, ['en', 'es'], true) ? $locale : 'es';
    }

    /**
     * @param  array<int, array<string, mixed>>  $recentMessages
     */
    private function buildRecentlyShownMediaPrompt(Profile $profile, array $recentMessages): ?string
    {
        $mediaId = null;

        for ($index = count($recentMessages) - 1; $index >= 0; $index--) {
            $message = $recentMessages[$index];

            if (($message['role'] ?? null) !== 'assistant' || empty($message['media_ids']) || ! is_array($message['media_ids'])) {
                continue;
            }

            $candidateId = (int) ($message['media_ids'][0] ?? 0);

            if ($candidateId > 0) {
                $mediaId = $candidateId;
                break;
            }
        }

        if ($mediaId === null) {
            return null;
        }

        $media = $profile->integrationMedia()
            ->whereKey($mediaId)
            ->first(['id', 'provider', 'caption', 'observation', 'permalink', 'taken_at', 'media_type', 'media_url', 'thumbnail_url']);

        if (! $media) {
            return null;
        }

        $mediaService = app(ProfileMediaPromptService::class);
        $json = json_encode([
            'id' => $media->id,
            'provider_key' => $media->provider,
            'provider_label' => $mediaService->providerLabel($media->provider),
            'type' => $media->media_type,
            'caption' => $media->caption,
            'observation' => $media->observation,
            'permalink' => $media->permalink,
            'image_url' => $media->media_url ?: $media->thumbnail_url,
            'date' => $media->taken_at?->toDateString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : null;
    }

    /**
     * @param  array<int, string>  $providers
     */
    private function providerListForPrompt(array $providers): string
    {
        $providers = array_values(array_filter(array_map(
            fn (string $provider): string => trim($provider),
            $providers
        )));

        return $providers !== [] ? implode(', ', $providers) : 'none';
    }

    private function socialNetworkName(string $network): string
    {
        $definition = config("social-networks.networks.{$network}", []);

        if (is_array($definition) && filled($definition['name'] ?? null)) {
            return (string) $definition['name'];
        }

        if (strtolower($network) === 'x') {
            return 'X';
        }

        return ucwords(str_replace(['_', '-'], ' ', $network));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentChatMessages(Profile $profile, ?int $chatId, ?int $currentMessageId): array
    {
        if (! $profile->exists || ! $chatId) {
            return [];
        }

        return $profile->messages()
            ->where('chat_id', $chatId)
            ->when($currentMessageId, fn ($query) => $query->where('id', '!=', $currentMessageId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['id', 'type', 'text', 'data', 'created_at'])
            ->reverse()
            ->values()
            ->map(function ($message): array {
                $payload = [
                    'role' => $message->type === 'answer' ? 'assistant' : 'user',
                    'text' => $message->text,
                ];
                $mediaIds = $this->messageMediaIds($message->data ?? null);

                if ($mediaIds !== []) {
                    $payload['media_ids'] = $mediaIds;
                }
                $productIds = $this->messageProductIds($message->data ?? null);

                if ($productIds !== []) {
                    $payload['product_ids'] = $productIds;
                }

                return $payload;
            })
            ->all();
    }

    /**
     * @return array<int>
     */
    private function messageMediaIds(mixed $data): array
    {
        if (! is_array($data) || ! isset($data['media']) || ! is_array($data['media'])) {
            return [];
        }

        return collect($data['media'])
            ->map(fn ($item): int => is_array($item) ? (int) ($item['id'] ?? 0) : 0)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int>
     */
    private function messageProductIds(mixed $data): array
    {
        if (! is_array($data) || ! isset($data['products']) || ! is_array($data['products'])) {
            return [];
        }

        return collect($data['products'])
            ->map(fn ($item): int => is_array($item) ? (int) ($item['id'] ?? 0) : 0)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Calculate confidence score from OpenAI response.
     */
    private function calculateConfidence(array $responseData): ?float
    {
        // OpenAI doesn't provide direct confidence scores for chat completions
        // We can estimate based on usage tokens and finish reason
        if (! isset($responseData['usage']) || ! isset($responseData['choices'][0]['finish_reason'])) {
            return null;
        }

        $finishReason = $responseData['choices'][0]['finish_reason'];
        $usage = $responseData['usage'];

        // Base confidence on finish reason
        $confidence = match ($finishReason) {
            'stop' => 0.9,        // Natural completion
            'length' => 0.7,      // Hit token limit
            'content_filter' => 0.3, // Content filtered
            default => 0.5
        };

        // Adjust based on token usage efficiency
        if (isset($usage['completion_tokens']) && isset($usage['prompt_tokens'])) {
            $responseRatio = $usage['completion_tokens'] / ($usage['prompt_tokens'] + $usage['completion_tokens']);
            // Prefer responses that are neither too short nor too long relative to prompt
            if ($responseRatio > 0.1 && $responseRatio < 0.8) {
                $confidence += 0.1;
            }
        }

        return min(1.0, $confidence);
    }

    /**
     * Calculate confidence score from Whisper transcription response.
     */
    private function calculateTranscriptionConfidence(array $responseData): ?float
    {
        // Whisper doesn't provide direct confidence scores in the API
        // We can estimate based on available metadata
        if (! isset($responseData['text'])) {
            return null;
        }

        $text = $responseData['text'];
        $baseConfidence = 0.8; // Default confidence for successful transcription

        // Adjust based on text characteristics
        if (strlen($text) < 10) {
            $baseConfidence -= 0.2; // Very short text might be less reliable
        }

        // Check for common transcription artifacts that might indicate lower quality
        $artifacts = ['[inaudible]', '[unclear]', '***', '...'];
        foreach ($artifacts as $artifact) {
            if (str_contains(strtolower($text), $artifact)) {
                $baseConfidence -= 0.1;
            }
        }

        return max(0.1, min(1.0, $baseConfidence));
    }
}
