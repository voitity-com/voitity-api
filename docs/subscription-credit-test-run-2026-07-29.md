# Subscription and Credit Test Run - 2026-07-29

## Scope and Safety

- API: local Docker application and PostgreSQL.
- Admin: `http://localhost:3000`.
- Public web: `http://localhost:3001`.
- Disposable user: `credit-cycle-20260729@bigmelo.test`.
- Payments: Wompi Sandbox with official approved test-card data.
- Providers: OpenAI, ElevenLabs, and Runway were not called during functional
  quota exhaustion; their adapters are covered with fakes in automated tests.
- Production was not deployed or modified.

## Cycle Results

| Cycle | Scope | Finding | Adjustment and verification |
| ---: | --- | --- | --- |
| 1 | Full API regression | 19 failures exposed legacy monthly-credit assumptions, an unsaved default `product_type`, and pre-migration releases without `plan_covered`. | Added the model default and legacy release fallback; updated obsolete assertions. The focused 96-test regression passed. |
| 2 | Static contracts and builds | Admin lint rejected two `Array<T>` declarations. API Pint also identified style issues in changed files. | Converted to `T[]`, formatted only changed PHP files, and passed admin lint/build plus web build. |
| 3 | Public web, legal pages, plan checkout | ES/EN prices, limits, credit disclosures, legal pages, and the plain `Ingresar` link rendered without overflow. | Activated a monthly Starter trial through Wompi Sandbox, then converted it through `subscriptions:bill-recurring`; the Sandbox charge was approved. |
| 4 | All plan limits and all credit-backed services | All eight included metrics reached zero without touching the wallet. A second profile correctly remained a hard limit. | Exercised image, video, clone, TTS, chat, and incoming audio overages. Exactly `143.445` credits were consumed and every use was finalized with separate `credit_covered` data. |
| 5 | Analytics date filter | Usage at 23:xx in Colombia was stored on the next UTC day and incorrectly omitted from the local "today" range. | Added the validated IANA `timezone` parameter, UTC boundary conversion, and timezone-aware buckets. Six analytics tests passed; the UI then displayed `143.445`. |
| 6 | Monthly renewal | A renewed period had to restore limits while retaining the wallet. | Forced only the disposable subscription due, ran the recurring billing command, and received one approved renewal. The next TTS character used plan capacity; wallet units stayed `856555`. |
| 7 | Buy after exhaustion | The post-renewal TTS limit reached zero while the wallet remained unchanged. | Bought another 1,000 credits in Billing after exhaustion. The next character consumed exactly `0.025` credits and created one reserve plus one consume ledger entry. |
| 8 | Reservation recovery and runtime | A provider reservation must not strand wallet units. | Reserved `0.250` TTS credits, released it, and verified available/reserved/lifetime totals returned exactly. Queue and scheduler restarted cleanly; stale release is scheduled every 10 minutes. |
| 9 | Responsive and bilingual UI, API clients | Billing, wallet history, analytics, purchase dialogs, Wompi link, and cancellation confirmation needed desktop/mobile and ES/EN coverage. Direct reload initially reverted the Admin locale. | Verified at default desktop and `390x844`; no horizontal overflow. Persisted the Admin language in `localStorage`, synchronized `<html lang>`, and verified Spanish and English after direct reload/navigation. Updated Postman and docs with timezone behavior. |
| 10 | Final regression | No regressions. The 29 rows already present in `failed_jobs` predate this implementation; the newest is from July 9, and no new failed job was produced by the credit tests. | Unit: 354 passed / 1,353 assertions. Feature: 316 passed / 1,833 assertions. Pint: 59 files passed. Admin lint/build, Web build, Swagger generation, Postman JSON validation, migrations, stale cleanup, queue, and scheduler passed. |

## Functional Accounting Evidence

After the first plan period was exhausted:

| Metric | Included used | Credit overage | Credits |
| --- | ---: | ---: | ---: |
| Profiles | 1 | Rejected as hard limit | 0 |
| Avatar image | 1 | 1 image | 12.5 |
| Avatar video | 5 seconds | 1 second | 30 |
| Voice clone | 1 | 1 clone | 100 |
| TTS | 20,000 characters | 1 character | 0.025 |
| Chat | 1,000 messages | 1 message | 0.17 |
| Incoming audio | 500 / 15,000 seconds | 1 / 30 seconds | 0.75 |

Total purchased-credit use: `143.445`.

After the second 1,000-credit purchase and one additional TTS character:

- available units: `1,856,530`;
- reserved units: `0`;
- lifetime purchased units: `2,000,000`;
- lifetime consumed units: `143,470`.

## Runtime Evidence

`php artisan schedule:list` includes:

- hourly `subscriptions:bill-recurring`;
- every-minute `subscriptions:expire-ended`;
- daily `subscriptions:reset-usage-limits`;
- every-10-minute `subscriptions:release-stale-usage-reservations`.

The local `queue` and `scheduler` containers were restarted after implementation
and remained running. Scheduler logs showed successful expiration and stale
reservation commands.

## Final Regression Evidence

| Check | Result |
| --- | --- |
| API unit suite | 354 passed, 1,353 assertions |
| API feature suite | 316 passed, 1,833 assertions |
| API total | 670 passed, 3,186 assertions |
| PHP formatting | 59 files passed |
| Admin lint | Passed |
| Admin production build | Passed |
| Public Web production build | Passed |
| OpenAPI/Swagger generation | Passed |
| Postman collection JSON | Valid |
| Credit migration | Applied locally in batch 47 |
| Queue and scheduler | Running with zero pending jobs |
| Historical failed jobs | 29 pre-existing rows; no new row during this run |

## Residual Risk

- Sandbox validates Wompi behavior but cannot prove production acquirer,
  dispute, or latency behavior.
- Provider APIs were intentionally not charged in the exhaustion scenario.
  Automated adapter tests validate request, success, failure, and release paths.
- Bundle-size warnings in the admin production build are pre-existing and do
  not block credit functionality.
