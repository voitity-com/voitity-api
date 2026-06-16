---
name: voitity-ai-service-adapter
description: Use when implementing or reviewing third-party API integrations for Voitity ChatAIService, VideoAIService, or VoiceService using the existing adapter, manager, DTO, config, provider, and test patterns.
---

# Voitity AI Service Adapter Workflow

## Before Editing

Read `docs/codex/service-adapters.md`.

Then inspect the current service family:

- Interface: `*Client.php`
- Manager: `*Manager.php`
- Provider adapter: existing provider namespace
- DTO/result objects
- Service wrapper
- Config file in `src/config`
- Service provider in `src/app/Providers`
- Unit tests for manager, provider, DTOs, and service wrapper

## Implementation Rules

Keep the application contract stable. Only change a `Client` interface when the
application truly needs a new capability, not because a provider has a unique
payload.

Provider-specific concerns must stay inside the adapter:

- URLs
- headers
- auth format
- request payloads
- response parsing
- status normalization
- provider error bodies

Controllers, listeners, jobs, and domain services should receive normalized
DTOs or use service wrappers, not raw provider responses.

## New Driver Checklist

1. Add provider config under `src/config/{service}.php`.
2. Add env-backed values for API key, base URL, model names, versions, and
   defaults.
3. Create the provider adapter class under the service namespace.
4. Implement the service `Client` interface exactly.
5. Add `create{Provider}Driver()` to the manager.
6. Return existing DTOs or create a focused DTO if the interface requires it.
7. Normalize success, pending, failure, and exception states.
8. Avoid real network calls in tests.
9. Add unit tests for manager, service provider, DTOs, service wrapper, and HTTP
   mapping.
10. Update docs/config examples when the public setup changes.

## Testing

Use `Http::fake()` for provider adapters and Mockery for managers or service
wrappers. Test success, HTTP error, exceptions, missing config, and custom
driver support when applicable.

Run the narrow test first:

```sh
docker compose exec app php artisan test --filter=ProviderOrManagerTest
```

Then run the related suite if the change touches shared contracts:

```sh
docker compose exec app php artisan test --testsuite=Unit
```
