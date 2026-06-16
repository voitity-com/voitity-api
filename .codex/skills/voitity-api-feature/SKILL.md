---
name: voitity-api-feature
description: Use when implementing, reviewing, or documenting Voitity Laravel API features, including routes, controllers, requests, responses, auth abilities, migrations, tests, Swagger, and Postman updates.
---

# Voitity API Feature Workflow

## Before Editing

Inspect the related route, controller, model, request, response, factory, and
test files before changing code.

Read only the docs needed for the task:

- `docs/codex/api-patterns.md` for endpoint structure.
- `docs/codex/auth-rules.md` for ability, admin, and owner rules.
- `docs/codex/testing.md` for test expectations.
- `docs/codex/chat-runtime.md` for chat, profile, and message flows.
- `docs/codex/service-adapters.md` for third-party service integrations.

## Endpoint Checklist

For every new or changed endpoint:

1. Add or update the route in `src/routes/api/v1/api.php`.
2. Use Sanctum middleware and the correct ability.
3. Validate inputs through FormRequest or controller validation.
4. Validate resource existence.
5. Validate owner/admin access.
6. Keep response shape consistent with existing response classes.
7. Add or update Feature tests.
8. Update Swagger annotations when applicable.
9. Update Postman when the public endpoint changes.
10. Run the smallest relevant test first.

For schema changes, create a new migration and do not edit historical
migrations. For finite string states, prefer a PHP backed enum under
`src/app/Enums`, validate it with `Rule::enum(...)`, cast it on the Eloquent
model, and return the enum value in response payloads. Store the database
column as a string in migrations unless a native database enum is explicitly
required.

For subscription usage or quota tracking, dispatch events and handle accounting
in queued listeners through `SubscriptionUsageRecorder`; keep controllers and
API responses free of direct accounting work.

## Authorization

Protected resources must validate authentication, ability, and ownership.
Admin users may bypass ownership only where the feature explicitly supports it
or where the existing pattern already does so.

## Verification

Prefer Docker from the repo root:

```sh
docker compose exec app php artisan test --filter=RelevantTest
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint
```

Do not run destructive database or Docker commands unless the user explicitly
asks.
