---
name: voitity-subscription-limit-testing
description: Use when adding a Bigmelo subscription plan, changing prices or limits, or validating quota enforcement across the Laravel API, public web, admin, queues, billing periods, and profile access.
---

# Voitity Subscription Limit Testing

Validate subscription changes in five test-and-adjust cycles. Treat
`src/config/subscriptions.php` as the canonical contract and do not copy limit
numbers into new tests unless the number itself is the behavior under test.

## Safety

- Run automated API tests only with `APP_ENV=testing` and SQLite in memory.
- Stop if the test output does not say `Database safety verified: Using SQLite
  in-memory database`.
- Never call OpenAI, ElevenLabs, Runway, Wompi, Instagram, TikTok, or OnlyFans
  in these tests. Fake clients, HTTP, storage, mail, and notifications.
- Use only disposable local users for browser and PostgreSQL checks.
- Record every local database value before changing it and restore it in a
  `finally` step or immediately after the browser assertion.
- Do not deploy as part of this workflow.

## Discover The Contract

Before editing, inspect:

- `src/config/subscriptions.php`
- `src/app/Enums/SubscriptionPlan.php`
- `src/app/Classes/Subscriptions/SubscriptionLimitPeriodService.php`
- `src/app/Classes/Subscriptions/SubscriptionUsageRecorder.php`
- `src/app/Classes/Subscriptions/SubscriptionPlanCapabilityService.php`
- `src/app/Classes/Subscriptions/SubscriptionProfileAccessService.php`
- public and authenticated subscription-plan response tests
- web and admin plan translations and rendering

Use `Tests\Support\CreatesSubscriptionScenarios` to create subscriptions and
limits from configuration. Extend that helper when a new billing interval or
status is introduced.

## Cycle 1: Contract And Boundaries

1. Compare public plans, authenticated plans, created limit rows, and UI copy
   with the canonical configuration.
2. For every finite metric, test zero, exactly the remaining amount, and one
   unit over the remaining amount.
3. Test incoming audio at the configured maximum duration and one second over.
4. Verify rejected requests create no message, usage row, or provider call.
5. Change product and selected-media capabilities temporarily in test config
   and prove enforcement follows the plan rather than legacy global settings.
6. Verify capability value `0` is valid and disables the feature.

Run:

```sh
docker compose exec -T app php artisan test \
  tests/Unit/Classes/Subscriptions/SubscriptionPlanCapabilityServiceTest.php \
  tests/Unit/Classes/Subscriptions/SubscriptionPlanLimitContractTest.php \
  tests/Feature/Http/Controllers/api/v1/PublicSubscriptionPlansControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/ProfileProductControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/ProfileIntegrationControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/MessageControllerTest.php
```

## Cycle 2: Atomic Accounting

1. Reserve the last available unit and verify a competing reservation fails
   before a provider call.
2. Repeat the same idempotency key and verify no double charge.
3. Reuse a non-released key with different user, profile, type, or amounts and
   verify rejection plus a warning log.
4. Release a reservation and verify metrics and credits are restored once.
5. Finalize a reservation twice and verify the second call is a no-op.
6. Repeat the smallest fractional charge enough times to reach a whole credit.
   Assert both the remaining balance and the sum of usage rows within
   `0.000001`.
7. Confirm PostgreSQL credit columns retain `NUMERIC(14,6)`.

Run:

```sh
docker compose exec -T app php artisan test \
  tests/Unit/Classes/Subscriptions/SubscriptionUsageRecorderTest.php \
  tests/Unit/Classes/Subscriptions/SubscriptionEntitlementServiceTest.php \
  tests/Unit/Listeners/Subscriptions/RecordSubscriptionUsageTest.php
```

## Cycle 3: Periods And Profile Access

1. Iterate every active plan and compare initialized metrics and credits with
   configuration.
2. Verify monthly, annual, and trial billing dates independently from monthly
   usage-period reset dates.
3. Verify a period reset does not move the paid renewal date.
4. Test trial conversion, paid renewal, declined renewal, cancellation at
   period end, and an active replacement subscription.
5. Verify profiles remain active until access ends.
6. Verify expiration deactivates profiles and prevents activation without an
   active subscription.
7. When a replacement plan allows fewer active profiles, verify all conflicting
   profiles are hidden for explicit reselection and only the allowed count can
   be activated.

Run:

```sh
docker compose exec -T app php artisan test \
  tests/Unit/Classes/Subscriptions/SubscriptionPlanLimitContractTest.php \
  tests/Unit/Classes/Subscriptions/SubscriptionLimitPeriodServiceTest.php \
  tests/Unit/Classes/Subscriptions/SubscriptionProfileAccessServiceTest.php \
  tests/Feature/Console/RecurringBillingCommandTest.php \
  tests/Feature/Console/SubscriptionExpirationCommandTest.php \
  tests/Feature/Http/Controllers/api/v1/PaymentControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/ProfileControllerTest.php
```

## Cycle 4: Scheduler, Queue, And Recovery

1. Create stale, current, finalized, and released reservations.
2. Run `subscriptions:release-stale-usage-reservations` twice. Verify only the
   stale reservation is released and the second run reports zero.
3. Run `php artisan schedule:list` and verify billing, expiration, renewal,
   reset, and stale-reservation commands.
4. Verify critical schedules use both `withoutOverlapping` and `onOneServer`.
5. Verify the shared cache supports distributed locks.
6. Verify queue `retry_after` is greater than the worker `--timeout` and
   database dispatch uses `after_commit`.
7. Confirm worker and scheduler containers use restart policies and the same
   release, database, cache, queue, and application key.
8. Inspect failed jobs, queue lag, scheduler logs, stale-reservation logs, and
   the composite stale-reservation index.

Run:

```sh
docker compose exec -T app php artisan test \
  tests/Feature/Console/SubscriptionUsageReservationCommandTest.php \
  tests/Feature/Console/SubscriptionScheduleConfigurationTest.php
docker compose exec -T app php artisan schedule:list
docker compose exec -T queue php artisan tinker --execute="dump(config('queue.connections.database'));"
```

## Cycle 5: Browser And Responsive UI

Test with an isolated local subscribed user.

1. Public site at `http://localhost:3001/`: monthly and annual prices, limits,
   ES/EN copy, checkout URLs, and the `Ingresar`/`Sign in` locale.
2. Admin billing at
   `http://localhost:3000/dashboard/settings/billing`: active plan, cycle,
   renewal date, prices, features, and cancellation confirmation.
3. Admin usage at
   `http://localhost:3000/dashboard/settings/usage`: every metric, remaining
   value, percentage, and ES/EN labels.
4. Public profile: enabled microphone, 30-second help text, exhausted incoming
   audio message count, exhausted incoming audio seconds, and restored state.
5. Validate at 1440x900 and 390x844. Check screenshots, horizontal overflow,
   clipped text, overlapping controls, and console errors.
6. After each language change, assert translated content and
   `document.documentElement.lang`.
7. Restore temporary quota changes before ending the cycle.

## Full Regression

Run Unit and Feature separately because the combined Laravel process may exceed
the local 128 MB PHP memory limit:

```sh
docker compose exec -T app php artisan test --testsuite=Unit
docker compose exec -T app php artisan test --testsuite=Feature
docker compose exec -T app vendor/bin/pint --test <changed-php-files>
docker exec voitity-admin-app npm run build
docker exec voitity-web-app npm run build
```

Pass every changed or newly created PHP file to Pint. Do not run Pint in
write mode across an existing dirty worktree because it can reformat unrelated
work.

Update Swagger and Postman only when an endpoint, request, response, error code,
or authentication contract changes.

## Report Results

For each cycle, record:

| Field | Required content |
| --- | --- |
| Scope | Tests and environments used |
| Expected | Boundary or invariant |
| Observed | Exact result before adjustment |
| Finding | Root cause and impact |
| Adjustment | Files and behavior changed |
| Verification | Passing tests, assertions, screenshots, and restored data |
| Residual risk | Anything not simulated, especially real providers |

Do not report a cycle as complete until its focused tests pass after the
adjustment.
