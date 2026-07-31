---
name: voitity-subscription-limit-testing
description: Use when adding a Bigmelo plan, changing prices, limits, or credit tariffs, or validating quota and purchased-credit enforcement across API, billing, providers, queues, analytics, and responsive UI.
---

# Voitity Subscription and Credit Testing

Validate changes in ten focused test-and-adjust cycles. Treat
`src/config/subscriptions.php` as the canonical plan, package, and tariff
contract. A cycle is complete only after its focused checks pass again after
the adjustment.

## Safety

- Run automated API tests only with `APP_ENV=testing` and SQLite in memory.
- Stop if output does not confirm `Database safety verified: Using SQLite
  in-memory database`.
- Fake OpenAI, ElevenLabs, Runway, Wompi, social networks, storage, mail, and
  notifications in automated tests.
- Use a disposable local user for PostgreSQL and browser checks.
- Snapshot every local database value before a temporary change and restore it.
- Do not perform a production deployment.

## Discover the Contract

Inspect:

- `src/config/subscriptions.php`
- `SubscriptionLimitPeriodService`
- `SubscriptionUsageFundingService`
- `SubscriptionUsageRecorder`
- `CreditWalletService`
- `CreditPurchaseService`
- `SubscriptionPlanCapabilityService`
- `SubscriptionProfileAccessService`
- `ProfileMessagingCapabilitiesService`
- payment and Wompi webhook handlers
- public/admin plan responses and ES/EN copy

Use `Tests\Support\CreatesSubscriptionScenarios`. Never hand-build limit rows
when the configured contract is what the test intends to validate.

## Cycle 1: Contract and Pricing

1. Compare public plans, authenticated plans, initialized limits, admin copy,
   public copy, Terms, Privacy, and Data Deletion.
2. Verify Starter monthly and annual prices.
3. Verify included monthly limits contain no purchased-credit allowance.
4. Verify package minimum, maximum, step, presets, USD price, and tariff
   version.
5. Recalculate provider-cost share for every tariff and keep it at or below the
   configured target.
6. Recheck official provider pricing before changing a tariff.

## Cycle 2: Plan-First Boundaries

For every finite metric, test:

1. Zero remaining with no wallet.
2. Exactly enough included capacity.
3. One unit crossing from plan into wallet.
4. Completely exhausted plan with enough wallet.
5. Wallet one internal unit short.
6. Included capacity is always consumed before purchased credits.
7. A metric does not spend credits because a different metric is exhausted.
8. Profile and capability hard limits do not consume credits.

## Cycle 3: Wallet Atomicity

1. Reserve the last wallet units and reject a competing reservation.
2. Finalize twice and verify one consumption.
3. Release twice and verify one restoration.
4. Repeat the smallest fractional tariff and prove no rounding drift.
5. Verify integer internal units and displayed decimal conversion.
6. Verify debt blocks reservations.
7. Verify a released reservation pays reversal debt before becoming available.

## Cycle 4: Purchase and Webhook Lifecycle

1. Reject trial, inactive subscription, missing reusable source, invalid step,
   below minimum, and above maximum.
2. Test approved, pending, declined, and provider error.
3. Repeat the API idempotency key and prove one charge and one grant.
4. Reuse the key with different data and expect validation failure.
5. Repeat the Wompi event and prove one ledger purchase.
6. Change approved to voided and verify balance removal or debt.
7. Prove a credit pack never activates or renews a subscription.

## Cycle 5: Public Text and Audio

1. Test text with plan, wallet, and no funding.
2. Test incoming audio at 1 second, 30 seconds, and 31 seconds.
3. Exhaust audio count while seconds remain, then the inverse.
4. Verify the full transcription duration uses credits when either audio metric
   is exhausted.
5. Verify failed validation creates no message, use, or provider call.
6. Verify messaging capabilities and microphone state reflect affordable
   duration.
7. Test simultaneous requests for the last message and audio units.

## Cycle 6: Paid Provider Jobs

1. TTS: included, split, wallet-only, insufficient, provider success, provider
   exception, and text fallback.
2. Avatar image/video: reserve, success, failure, retries, and stale release.
3. Voice: first clone, re-clone with credits, unique provider-request keys,
   success finalization, failure release, and replaced-provider cleanup.
4. Verify no provider starts before a successful reservation.
5. Verify a provider-consumed operation is not refunded merely because a later
   local step failed.

## Cycle 7: Periods, Renewal, and Access

1. Verify monthly and annual billing dates independently from monthly usage
   periods.
2. Exhaust each included metric and consume wallet credits.
3. Move to the next monthly period.
4. Verify plan limits are restored and wallet balance is unchanged.
5. Verify new operations use renewed plan capacity before wallet.
6. Test trial conversion, renewal, decline, cancellation, expiration, and
   replacement subscription.
7. Verify expired access deactivates profiles and leaves wallet dormant.
8. Verify a replacement one-profile plan requires explicit active-profile
   selection.

## Cycle 8: Analytics and Audit

1. Compare current limits, usage rows, wallet, ledger, and `/api/usage`.
2. Verify plan-covered and credit-covered metrics are separate.
3. Verify reserved and consumed credits are separate.
4. Verify released usage is excluded.
5. Filter by day, month, one month, multiple months, and the 24-month boundary.
6. Test usage immediately before and after UTC midnight with a non-UTC IANA
   timezone; verify both range inclusion and bucket labels.
7. Verify history remains visible without an active subscription.
8. Reconcile purchase, reversal, available, reserved, debt, and lifetime
   counters.

## Cycle 9: Browser and Responsive UI

Use a disposable signed-in local user.

1. Public site: prices, limits, additional-credit copy, legal pages, and
   `Ingresar`/`Sign in`.
2. Billing: wallet totals, packages, custom amount validation, tariffs, Wompi
   link, terms acceptance, purchase result, history, and inactive/trial state.
3. Usage: current plan limits, wallet summary, date filters, grouping, chart,
   and period table.
4. Public profile: text input, microphone, affordable duration, and TTS
   fallback.
5. Validate Spanish and English at 1440x900 and 390x844.
6. Check horizontal overflow, clipping, overlap, keyboard focus, dialog scroll,
   browser console, and failed network requests.

## Cycle 10: Runtime and Full Regression

1. Run stale-reservation release twice and verify idempotency.
2. Inspect `php artisan schedule:list`.
3. Verify `withoutOverlapping`, `onOneServer`, shared cache locks, queue
   `after_commit`, `retry_after`, and worker timeout.
4. Inspect queue, scheduler, and failed-job state.
5. Run the PostgreSQL migration and smoke test actual tables and constraints.
6. Run Unit and Feature suites separately.
7. Run Pint on changed PHP files, Swagger generation, admin build, and web
   build.
8. Re-run the browser smoke path after all fixes.

## Commands

```sh
docker compose exec -T app php artisan test \
  tests/Unit/Classes/Subscriptions/SubscriptionPurchasedCreditsTest.php \
  tests/Unit/Classes/Subscriptions/CreditWalletServiceTest.php \
  tests/Feature/Http/Controllers/api/v1/CreditControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/UsageAnalyticsControllerTest.php

docker compose exec -T app php artisan test --testsuite=Unit
docker compose exec -T app php artisan test --testsuite=Feature
docker compose exec -T app php artisan schedule:list
docker compose exec -T app php artisan l5-swagger:generate
(cd ../voitity-admin/src && npm run lint && npm run build)
(cd ../voitity-web/src && npm run build)
```

Pass only changed PHP files to `vendor/bin/pint --test`. Do not run Pint in
write mode across an unrelated dirty worktree.

## Report Each Cycle

Record:

| Field | Required content |
| --- | --- |
| Scope | Tests, user, and environment |
| Expected | Boundary or invariant |
| Observed | Exact pre-adjustment result |
| Finding | Root cause and impact |
| Adjustment | Files and behavior changed |
| Verification | Tests, assertions, screenshots, and restored data |
| Residual risk | Especially unsimulated provider behavior |
