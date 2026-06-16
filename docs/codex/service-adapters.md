# Third-Party Service Adapter Pattern

ChatAIService, VideoAIService, and VoiceService follow a Laravel manager plus
adapter pattern. Use this pattern for new third-party APIs.

## Existing Services

### ChatAIService

Files:

- `src/app/Classes/ChatAIService/ChatAIClient.php`
- `src/app/Classes/ChatAIService/ChatAIManager.php`
- `src/app/Classes/ChatAIService/OpenAI/OpenAIClient.php`
- `src/app/Classes/ChatAIService/ChatAIAnswer.php`
- `src/app/Classes/ChatAIService/ChatAITextFromAudio.php`
- `src/app/Classes/ChatAIService/AnswerBuilder.php`
- `src/config/chatai.php`
- `src/app/Providers/ChatAIServiceProvider.php`

Contract:

- `getAnswer(Profile $profile, string $message, ?int $chatId, ?int $currentMessageId): ChatAIAnswer`
- `getTextFromAudio(string $audioPath): ChatAITextFromAudio`

The current OpenAI driver also builds the prompt. For larger prompt, agent,
skill, document, or retrieval changes, prefer extracting that logic out of the
provider client.

### VideoAIService

Files:

- `src/app/Classes/VideoAIService/VideoAIClient.php`
- `src/app/Classes/VideoAIService/VideoAIManager.php`
- `src/app/Classes/VideoAIService/Runway/RunwayVideoAI.php`
- `src/app/Classes/VideoAIService/AiImage.php`
- `src/app/Classes/VideoAIService/AiVideo.php`
- `src/app/Classes/VideoAIService/VideoAIService.php`
- `src/config/videoai.php`
- `src/app/Providers/VideoAIServiceProvider.php`

Contract:

- `createImage(string $sourceImage, string $prompt, string $ratio = ''): AiImage`
- `createVideo(string $sourceImage, string $prompt, string $ratio = '', int $duration = 5): AiVideo`
- `getImage(string $sourceId): AiImage`
- `getVideo(string $sourceId): AiVideo`

The adapter maps provider task responses into `AiImage` and `AiVideo`. The
service wrapper handles application persistence.

### VoiceService

Files:

- `src/app/Classes/VoiceService/VoiceClient.php`
- `src/app/Classes/VoiceService/VoiceManager.php`
- `src/app/Classes/VoiceService/ElevenLabs/ElevenLabsVoiceClient.php`
- `src/app/Classes/VoiceService/VoiceClientClonedVoice.php`
- `src/app/Classes/VoiceService/VoiceClientAddedSample.php`
- `src/app/Classes/VoiceService/VoiceClientGeneratedAudio.php`
- `src/app/Classes/VoiceService/VoiceService.php`
- `src/config/voice.php`
- `src/app/Providers/VoiceServiceProvider.php`

Contract:

- `cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice`
- `addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample`
- `generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio`

Voice uses domain models as adapter input because provider calls depend on
stored voice and sample metadata.

## Implementation Shape

When adding a new provider driver:

1. Keep or extend the `Client` interface only when the app contract truly needs
   a new capability.
2. Create a provider class under a provider namespace, for example
   `App\Classes\VideoAIService\ProviderName\ProviderNameVideoAI`.
3. Implement the existing interface exactly.
4. Read credentials and defaults from `config/{service}.php`.
5. Add `drivers.{provider}` config with env-backed values.
6. Add `create{StudlyProvider}Driver()` to the manager.
7. Return existing DTOs or add a focused DTO when the interface requires it.
8. Keep provider request payloads, headers, URLs, parsing, and status mapping in
   the provider adapter.
9. Bind new contracts in the service provider only if a new interface/service is
   introduced.
10. Add unit tests for manager, config, DTOs, service wrapper, and adapter HTTP
    mapping.

## Manager Pattern

Managers extend `Illuminate\Support\Manager`.

Expected methods:

- `getDefaultDriver(): string`
- `create{Provider}Driver(): ClientInterface`
- `driver($driver = null): ClientInterface`
- `createCustomDriver(array $config): ClientInterface`

Custom drivers should require `via` and call it through the container.

## DTO Pattern

Result objects should normalize provider responses and expose:

- provider/source identifier
- provider task or resource id when available
- normalized status
- request URL when useful for debugging
- raw provider response
- helper methods like `isSuccessful`, `isFailed`, `isPending`
- `toArray()` for persistence or API payloads

Do not leak raw provider response shape into controllers.

## Error Handling

Prefer normalized failure DTOs for recoverable provider failures, especially
async generation flows. Throw domain-specific exceptions only when the caller
already expects exception behavior or the operation cannot continue.

Log provider failures with enough context to debug:

- provider/source
- local model id when available
- provider source id when available
- HTTP status
- request URL
- sanitized response body

Never log API keys, bearer tokens, full auth headers, or private user content
unless explicitly required and safe.

## Tests

Do not call real providers in tests.

Use:

- Mockery for service wrapper and manager tests.
- `Http::fake()` for provider adapter tests.
- fake storage for file/audio flows.
- config overrides for API keys, base URLs, models, and defaults.

At minimum test:

- success mapping
- HTTP failure mapping
- thrown exception mapping
- missing required config when the driver requires it
- manager default and named driver resolution
- custom driver `via`
- service provider container resolution
