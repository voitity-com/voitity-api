# Codex Project Context

This folder contains compact project context for Codex.

Files:

- `prompts.md`: reusable prompts for common work.
- `api-patterns.md`: endpoint implementation checklist.
- `auth-rules.md`: ability, admin, and owner rules.
- `testing.md`: test commands and coverage expectations.
- `chat-runtime.md`: current chat flow and prompt caveats.
- `service-adapters.md`: ChatAIService, VideoAIService, and VoiceService adapter pattern.

Local Codex skills are installed in `.codex/skills`.

Current local skills:

- `voitity-api-feature`: API endpoints, auth, responses, tests, Swagger, Postman.
- `voitity-ai-service-adapter`: drivers for ChatAIService, VideoAIService, and VoiceService.
- `voitity-service-adapter`: new service families or external integrations using the adapter pattern.

Keep these files short. Add only information Codex cannot infer from the code.
