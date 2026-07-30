# Subscription Limit Test Run - 2026-07-29

Environment: local Docker, SQLite in-memory automated tests, local PostgreSQL,
public web on port 3001, admin on port 3000. No production deployment and no
real paid provider calls were made.

## Cycle 1 - Contract And Boundaries

- Baseline: 61 tests, 359 assertions.
- Finding: plans advertised product and selected-media capabilities from
  `subscriptions.php`, while enforcement still used global product/provider
  settings.
- Adjustment: added `SubscriptionPlanCapabilityService`; product creation, CSV
  imports, integration selection, prompt media, and API responses now resolve
  capabilities from the profile's active plan. The final regression also
  confirmed that prompt media must discover legacy providers such as Facebook
  dynamically while retaining per-provider plan limits.
- Verification: 64 tests, 369 assertions. Dynamic limits and capability value
  zero passed.

## Cycle 2 - Atomic Accounting

- Finding: rounding each one-character TTS operation to two decimals charged
  1.20 credits for work configured to cost 1.00 credit. A reused idempotency key
  also accepted a different usage payload silently.
- Adjustment: migrated credit columns to `NUMERIC(14,6)`, used six-decimal
  arithmetic, and rejected conflicting non-released idempotency keys with a
  warning log.
- Verification: 16 tests, 82 assertions. PostgreSQL reported precision 14 and
  scale 6 for both credit columns.

## Cycle 3 - Periods And Profile Access

- Baseline: 77 tests, 528 assertions.
- Finding: the reusable test helper assumed every subscription renewed monthly,
  which could create invalid annual test fixtures.
- Adjustment: renewal dates now derive from plan interval or trial days. Added
  a contract test that iterates all active plans and compares initialized
  limits, credits, billing dates, and usage periods with configuration.
- Verification: 79 tests, 574 assertions.

## Cycle 4 - Scheduler, Queue, And Recovery

- Finding: the queue worker timeout was 300 seconds while database
  `retry_after` was 90 seconds, allowing a slow job to become available for a
  second execution. Recurring billing lacked schedule overlap protection.
- Adjustment: set `retry_after` to 360, enabled `after_commit`, added
  single-server and overlap locks, processed stale reservations in chunks,
  logged empty sweeps, and indexed status/reservation time/id.
- Verification: 19 tests, 133 assertions. Local queue and scheduler containers
  were recreated and reported the effective 360-second retry window and
  `after_commit=true`.

## Cycle 5 - Browser And Responsive UI

- Coverage: public plans, login navigation, admin billing and usage, public
  profile microphone, ES/EN, 1440x900, and 390x844.
- Finding 1: the admin translated to Spanish but kept `<html lang="en">`.
- Finding 2: the admin language selector was hidden on mobile.
- Adjustment: synchronized the document language with i18next and rendered a
  compact flag-only language control on small screens.
- Verification: ES produced `lang=es`, EN produced `lang=en`; both mobile and
  desktop had no horizontal overflow. The microphone disabled for zero incoming
  messages and zero incoming seconds, showed the 30-second rule when enabled,
  and the local quota values were restored.

## Residual Risk

- Real provider latency and billing webhooks were faked; staging should perform
  one controlled provider smoke test before release.
- True simultaneous PostgreSQL requests are protected by transactions and row
  locks and covered by competing-reservation behavior, but load testing with
  multiple worker processes remains a staging task.
- The complete Unit and Feature suites must be run separately to stay below the
  local PHP memory limit.

## Final Regression

- API Unit: 337 passed, 1,279 assertions.
- API Feature: 300 passed, 1,745 assertions.
- Total API: 637 passed, 3,024 assertions.
- Focused legacy-media regression: 67 passed, 292 assertions.
- Task-scoped Laravel formatting, admin production build/typecheck, public web
  build, and skill validation completed successfully.
