# Chat Runtime

Current flow:

1. `MessageController` validates the profile, chat, request, and subscription.
2. Text requests atomically reserve one visitor message before persistence.
3. Audio requests inspect the real duration, reject recordings over 30 seconds,
   and atomically reserve visitor-message, audio-count, and audio-duration quota
   before transcription.
4. The accepted question is stored and its reservation is finalized.
5. `MessageStored` dispatches processing and `ProcessStoredMessage` loads the
   message, profile, and chat.
6. `AnswerBuilder` calls the configured `ChatAIClient`.
7. `OpenAIClient` builds the system prompt and calls OpenAI.
8. `AnswerBuilder` optionally generates voice audio through `VoiceService`.
9. `VoiceService` reserves TTS characters before the provider call, finalizes
   successful usage, and releases failed usage.
10. If TTS quota is unavailable, the answer is stored and returned as text only.

Important files:

- `src/app/Http/Controllers/api/v1/MessageController.php`
- `src/app/Classes/ChatAIService/AudioMessageInspector.php`
- `src/app/Listeners/AI/ProcessStoredMessage.php`
- `src/app/Classes/ChatAIService/AnswerBuilder.php`
- `src/app/Classes/Subscriptions/SubscriptionUsageRecorder.php`
- `src/app/Classes/Subscriptions/ProfileMessagingCapabilitiesService.php`
- `src/app/Classes/ChatAIService/ChatAIClient.php`
- `src/app/Classes/ChatAIService/OpenAI/OpenAIClient.php`
- `src/app/Models/Profile.php`
- `src/app/Models/Chat.php`
- `src/app/Models/Message.php`

## Current Prompt Caveat

`OpenAIClient` currently builds the system prompt directly from `Profile` data
and recent messages. This is acceptable for the current implementation, but it
is not the right place for larger business rules.

If a change introduces agents, skills, documents, retrieval, prompt versions,
or richer memory, extract that work into a dedicated builder/service before
expanding provider code.

Recommended future split:

- `AgentContextBuilder`: collects profile, instructions, history, docs, and skills.
- `PromptBuilder`: converts context into provider-ready messages.
- `ChatAIClient`: sends provider-ready payload and maps provider response.
- `AnswerBuilder`: orchestrates domain persistence and optional audio.
