# AGENTS.md

## Project

Voitity API is a Laravel 12 API located in `src/`.
The repository root contains Docker Compose and project-level docs.

Core stack:
- Laravel 12
- PHP 8.2+
- PostgreSQL with pgVector
- Laravel Sanctum
- L5 Swagger
- PHPUnit
- Laravel Pint

## Commands

Run project commands from the repository root unless the user says otherwise.

- Start app: `docker compose up -d --build`
- App status: `docker compose ps`
- Run migrations: `docker compose exec app php artisan migrate`
- Run all tests: `docker compose exec app php artisan test`
- Run one test: `docker compose exec app php artisan test --filter=TestName`
- Generate Swagger: `docker compose exec app php artisan l5-swagger:generate`
- Format PHP: `docker compose exec app ./vendor/bin/pint`
- Shell in app: `docker compose exec app sh`

Do not run destructive commands like `docker compose down -v`, `migrate:fresh`,
database resets, or filesystem cleanup unless the user explicitly asks.

## Architecture

- API routes live in `src/routes/api/v1/api.php`.
- API controllers live in `src/app/Http/Controllers/api/v1`.
- Request validation lives in `src/app/Http/Requests`.
- API response wrappers live in `src/app/Http/Responses`.
- Models live in `src/app/Models`.
- Third-party service adapters live in `src/app/Classes`.
- Application enums live in `src/app/Enums`.
- AI chat logic lives in `src/app/Classes/ChatAIService`.
- Video generation logic lives in `src/app/Classes/VideoAIService`.
- Voice generation and cloning logic lives in `src/app/Classes/VoiceService`.
- Authorization abilities are configured in `src/config/roles.php`.

Follow existing Laravel conventions and keep changes scoped.
Do not refactor unrelated code while implementing a feature.

## API Rules

When adding or changing an endpoint:

1. Add or update the route in `src/routes/api/v1/api.php`.
2. Use Sanctum middleware and the correct ability.
3. Validate input with a FormRequest when validation is non-trivial.
4. Validate ownership and admin access for protected resources.
5. Return a consistent JSON response, preferably through a response class.
6. Add or update Feature tests.
7. Add or update Swagger annotations when the controller uses them.
8. Update the Postman collection when the endpoint changes.

Default pagination for list endpoints is 20 unless the user specifies another
default.

For database changes, always create a new migration. Do not edit historical
migrations.

For finite string states such as profile status, prefer a PHP backed enum under
`src/app/Enums` and cast it from the Eloquent model. Store the column as a
string in migrations; avoid native database enums unless explicitly required.

## Authorization

Protected endpoints must validate:

- authenticated user
- required Sanctum ability
- admin access when the feature supports admin bypass
- owner access when the user is not admin

Avoid copying complex access rules across many controllers. If the same rule
appears repeatedly, prefer a small policy/helper/service.

## Service Adapter Pattern

ChatAIService, VideoAIService, and VoiceService use a provider adapter pattern:

- a `Client` interface defines the stable application contract
- provider classes implement that interface
- a `Manager` resolves drivers from config
- config files define `default` and `drivers`
- DTO/result objects normalize provider responses
- service wrappers contain domain orchestration and persistence
- service providers bind managers and interfaces into the container

When integrating a new third-party API, keep provider-specific payloads,
headers, response parsing, and error details inside the adapter class.
Controllers, listeners, and jobs should depend on service contracts or service
wrappers, not raw provider APIs.

See `docs/codex/service-adapters.md` before changing or adding a third-party
service integration.

## Chat Runtime

The current chat flow is:

`MessageController` -> `MessageStored` -> `ProcessStoredMessage` ->
`AnswerBuilder` -> `ChatAIClient` -> provider adapter.

`OpenAIClient` currently builds the system prompt internally. If a change adds
agent behavior, skills, documents, retrieval, or larger prompt rules, prefer
extracting prompt/context construction into a dedicated builder instead of
adding more business logic to the provider client.

## Testing

For API changes, add Feature tests covering:

- success response
- missing ability
- validation failure
- non-owner access
- admin access when applicable
- pagination when applicable

For service adapter changes, add Unit tests covering:

- manager driver resolution
- custom driver support when applicable
- service provider binding
- DTO/result object behavior
- service wrapper delegation
- provider HTTP success and failure mapping when practical

Do not call real third-party APIs in tests. Use mocks or HTTP fakes.

## Docs For Codex

Project-specific Codex docs live in `docs/codex`.
Local Codex skills live in `.codex/skills`.

Use those docs as the source of truth for prompts, API patterns, auth rules,
testing expectations, chat runtime notes, and third-party service adapters.
