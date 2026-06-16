# Chat Runtime

Current flow:

1. `MessageController` validates and stores a user question.
2. It dispatches `MessageStored`.
3. `ProcessStoredMessage` loads the message, profile, and chat.
4. `AnswerBuilder` calls the configured `ChatAIClient`.
5. `OpenAIClient` builds the system prompt and calls OpenAI.
6. `AnswerBuilder` optionally generates voice audio through `VoiceService`.
7. The answer is stored as a `Message`.

Important files:

- `src/app/Http/Controllers/api/v1/MessageController.php`
- `src/app/Listeners/AI/ProcessStoredMessage.php`
- `src/app/Classes/ChatAIService/AnswerBuilder.php`
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
