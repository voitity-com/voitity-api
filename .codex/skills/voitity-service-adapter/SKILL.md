---
name: voitity-service-adapter
description: Use when creating a new Voitity integration with a third-party API that should follow the project's adapter pattern, including new service folders, client interfaces, managers, provider drivers, DTOs, config, service providers, tests, and optional API endpoints.
---

# Voitity Service Adapter Workflow

Use this skill when the user wants to integrate a new external provider that
does not already fit inside ChatAIService, VideoAIService, or VoiceService.
Examples: payments, email delivery, SMS, KYC, analytics, CRM, storage,
shipping, billing, identity verification, notifications, or search.

## First Decision

Decide whether the request is:

- **New driver for an existing service**: use the existing service folder,
  interface, manager, config, DTOs, and tests.
- **New service family**: create a new `src/app/Classes/{ServiceName}Service`
  folder with its own interface, manager, service wrapper, provider driver,
  config, provider binding, DTOs, and tests.

Read as needed:

- `references/folder-structure.md` for subfolders and file placement.
- `references/contracts-and-dtos.md` for interface and result object design.
- `references/examples.md` for payment, email, and analytics examples.
- `references/testing-and-security.md` for tests, fakes, secrets, and logging.
- `docs/codex/service-adapters.md` for the existing Voitity AI service pattern.

## Implementation Rules

Keep provider details behind an adapter. Controllers, listeners, jobs, and
domain services should not know third-party payload shapes, headers, status
names, or error response formats.

Create a stable application contract first:

1. Define what the app needs to do.
2. Create or update a `Client` interface around those app capabilities.
3. Map provider-specific requests and responses inside a provider driver.
4. Return typed DTO/result objects from the interface.
5. Put persistence and domain orchestration in a service wrapper.
6. Resolve drivers through a Laravel `Manager`.
7. Read credentials and defaults from `config/{service}.php`.
8. Bind the manager and client interface in a service provider.
9. Test without real network calls.

## New Service Checklist

For a new service family, create:

- `src/app/Classes/{ServiceName}Service/{ServiceName}Client.php`
- `src/app/Classes/{ServiceName}Service/{ServiceName}Manager.php`
- `src/app/Classes/{ServiceName}Service/{ServiceName}Service.php`
- `src/app/Classes/{ServiceName}Service/{Provider}/{Provider}{ServiceName}Client.php`
- DTO/result objects under the service folder
- `src/config/{service}.php`
- `src/app/Providers/{ServiceName}ServiceProvider.php`
- unit tests under `src/tests/Unit/Classes/{ServiceName}Service`
- provider binding tests under `src/tests/Unit/Providers`

Add models, migrations, jobs, events, controllers, routes, Swagger, or Postman
only when the integration needs persistence, async processing, webhooks, or a
public API surface.

## Payment Service Baseline

When the user asks for a new payment integration and does not provide a
different structure, create this baseline:

- `src/app/Classes/PaymentService/PaymentClient.php`
- `src/app/Classes/PaymentService/PaymentManager.php`
- `src/app/Classes/PaymentService/PaymentService.php`
- `src/app/Classes/PaymentService/{Provider}/{Provider}PaymentClient.php`
- `src/app/Classes/PaymentService/PaymentRequest.php`
- `src/app/Classes/PaymentService/PaymentIntent.php`
- `src/app/Classes/PaymentService/PaymentRefundRequest.php`
- `src/app/Classes/PaymentService/PaymentRefund.php`
- `src/app/Classes/PaymentService/PaymentWebhook.php`
- `src/config/payment.php`
- `src/app/Providers/PaymentServiceProvider.php`
- unit tests for manager, provider binding, DTOs, service wrapper, and adapter

Only add payment models, migrations, routes, Swagger, Postman, jobs, or webhook
controllers when the requested payment flow needs persistence or API access.

## Verification

Use narrow tests first:

```sh
docker compose exec app php artisan test --filter={ServiceName}
```

Then run the related suite when shared contracts changed:

```sh
docker compose exec app php artisan test --testsuite=Unit
```

Never call real third-party APIs in tests.
