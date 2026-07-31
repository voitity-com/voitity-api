# Subscriptions, Purchased Credits, and Usage

This document is the operating contract for Bigmelo plans, purchased credits,
usage accounting, provider calls, billing periods, and public messaging.

## Canonical Configuration

The canonical plan and credit configuration is
`src/config/subscriptions.php`. Do not duplicate prices, limits, package sizes,
or tariffs in application code.

The public site reads `GET /api/subscription/public-plans`. The authenticated
admin reads `GET /api/subscription/plans`, `GET /api/subscription/limits`,
`GET /api/credits/catalog`, and `GET /api/usage`.

## Starter Plans

Both Starter plans have the same capacity per monthly usage period:

| Capability | Included |
| --- | ---: |
| Published profiles | 1 |
| Avatar images | 1 |
| Avatar video | 5 seconds |
| Authorized voice clones | 1 |
| Visitor messages, text or audio | 1,000 |
| Incoming audio messages | 500 |
| Maximum duration per incoming audio | 30 seconds |
| Total incoming audio duration | 15,000 seconds |
| Text converted to response audio | 20,000 characters |
| Products per profile | 15 |
| Selected media per Instagram or TikTok integration | 10 |
| Public social links | Enabled |

- Monthly Starter costs USD 12.99 per month.
- Annual Starter costs USD 129 per year.
- Annual billing renews once per year, but included usage resets monthly.
- Unused included capacity does not roll over.
- Starter and trial do not include a monthly purchased-credit balance.
- The seven-day trial has reduced included limits and cannot buy or consume
  purchased credits.

Products and selected integration media are enforced by
`SubscriptionPlanCapabilityService`. These capabilities are hard limits and do
not consume purchased credits.

## Purchased Credits

Purchased credits are a persistent overage wallet, separate from plan capacity.

| Rule | Behavior |
| --- | --- |
| Package price | USD 10 per 1,000 credits |
| Allowed purchase | 1,000 to 100,000 credits |
| Step | Multiples of 1,000 |
| Billing | One-time Wompi charge in COP |
| Eligibility | Active paid subscription and chargeable default Wompi payment method |
| Trial | Cannot purchase or consume |
| Monthly renewal | Wallet is preserved |
| Inactive subscription | Wallet remains dormant and cannot be consumed |
| Account deletion | Remaining balance is forfeited, subject to mandatory law |
| Transfer or cash value | Not supported |

Internally, one displayed credit is 1,000 integer units. Wallet and ledger
calculations never use binary floating point. API responses convert units back
to credits with up to three decimal places.

### Tariffs

The tariff version is `2026-07-29-v1`.

| Service | Purchased credits |
| --- | ---: |
| Visitor message | 0.17 per message |
| Incoming audio message count | 0 |
| Incoming audio transcription | 0.025 per second |
| Response audio generation | 0.025 per character |
| New avatar image | 12.5 per image |
| Avatar video | 30 per second |
| New voice clone | 100 per clone |

Incoming audio is treated as a bundle. If either included audio-message count or
included audio seconds cannot cover the request, purchased credits cover the
complete transcription duration. The visitor message metric is allocated
independently.

A new five-second avatar generated after the included allowance costs 162.5
credits: 12.5 for the image plus 150 for the video. Re-cloning a voice after the
included clone costs 100 credits.

### Forty Percent Provider-Cost Target

The package is priced so modeled variable provider cost is at most 40% of
revenue before Wompi fees, taxes, support, infrastructure, retries, and other
fixed costs.

| Service | Work bought by 1,000 credits | Modeled provider cost | Cost share of USD 10 |
| --- | ---: | ---: | ---: |
| Chat | 5,882 messages | USD 4.00 | 40% |
| Incoming audio | 40,000 seconds | USD 4.00 | 40% |
| Multilingual TTS | 40,000 characters | USD 4.00 | 40% |
| Avatar image | 80 images | USD 4.00 | 40% |
| Gen-4.5 avatar video | 33.33 seconds | USD 4.00 | 40% |

Voice cloning uses a commercial 100-credit tariff because the provider exposes
voice slots and plan capacity rather than a stable per-clone fee. Review this
rate whenever the ElevenLabs plan or custom-voice limits change.

Pricing assumptions were checked on July 29, 2026 against the official
[Runway API pricing](https://docs.dev.runwayml.com/guides/pricing/),
[ElevenAPI pricing](https://elevenlabs.io/pricing/api?price.platform=api), and
[OpenAI model pricing](https://developers.openai.com/api/docs/models/gpt-4o-mini).
Provider prices are external and must be rechecked before changing packages or
tariffs.

## Allocation Order

Every eligible operation follows the same invariant:

1. Validate that the user has an active subscription.
2. Lock the user, subscription limit, and wallet rows.
3. Allocate as much as possible to the corresponding included plan metric.
4. Price only the uncovered amount with the current credit tariff.
5. Reject before the provider call if the wallet cannot cover the full
   purchased-credit portion.
6. Reserve included capacity and wallet units atomically.
7. Finalize after success or after an irrevocable paid provider attempt.
8. Release both plan capacity and wallet units when the provider was not
   consumed.

Purchased credits are never used while the corresponding included metric can
cover the operation. Exhausting chat does not cause TTS to use credits, and
exhausting TTS does not cause chat to use credits. Each metric renews and spends
independently.

The profile count remains a hard plan limit. Credits cannot activate a second
profile on Starter.

## Wallet and Ledger

`credit_wallets` stores:

- `available_units`
- `reserved_units`
- `debt_units`
- `lifetime_purchased_units`
- `lifetime_consumed_units`

`credit_ledger_entries` is the immutable audit trail. Entry types are purchase,
reserve, consume, release, reversal, and adjustment. Every entry has a unique
idempotency key plus post-operation available, reserved, and debt snapshots.

Approved payment orders grant credits once. A repeated webhook or API retry
returns the existing order and does not grant again. Reusing a purchase
idempotency key with a different amount is rejected.

If an approved credit payment is later voided, disputed, or reversed:

1. Available units from that purchase are removed.
2. Any amount already consumed or reserved becomes wallet debt.
3. A release or future valid purchase pays debt first.
4. Purchased credits cannot be reserved while debt remains.

## Payment Flow

`POST /api/credits/purchases` requires:

```json
{
  "credits": 1000,
  "payment_source_id": 42,
  "idempotency_key": "client-generated-unique-key",
  "terms_accepted": true
}
```

The API calculates USD display price and COP processing amount from the current
exchange rate. Wompi is called with `recurrent=false` against the selected
reusable payment source. If `payment_source_id` is omitted, the chargeable
default is used. Selecting a secondary method for a one-time credit purchase
does not change the subscription renewal default.

- Approved: the wallet is credited synchronously and HTTP 201 is returned.
- Pending: HTTP 202 is returned and the Wompi webhook grants on approval.
- Declined or error: HTTP 402 is returned and no credits are granted.
- Later reversal: the webhook creates the wallet reversal and possible debt.

The credit pack does not activate, renew, replace, or extend a subscription.

## Visitor Messaging

### Text

1. Reserve one `chat_messages` unit.
2. Use included plan capacity first and purchased credits second.
3. Store the question.
4. Finalize the reservation.
5. Queue answer generation.

If neither source can cover the message, persistence and OpenAI calls are
blocked.

### Incoming Audio

1. Validate the 10 MB upload limit.
2. Read real duration with getID3.
3. Reject unknown duration or audio over 30 seconds.
4. Reserve chat, audio-message count, and audio seconds atomically.
5. Call transcription.
6. Finalize after the provider attempt.
7. Release only if failure happened before provider consumption.

`ProfileMessagingCapabilitiesService` disables the microphone if plan capacity
and wallet cannot cover at least one second of audio. It returns the affordable
maximum duration, capped at 30 seconds. Text remains enabled when chat can still
be covered.

## TTS, Avatar, and Voice Jobs

TTS reserves the exact Unicode character count before ElevenLabs is called.
When the plan and wallet cannot cover TTS, the generated answer remains text
and no audio provider request starts.

Avatar image and video operations reserve before Runway. Provider failures
release reservations; successful generations finalize them.

Voice cloning creates a unique reservation per `VoiceProviderRequest`. A
successful queued clone finalizes that reservation. A failed job releases it.
Re-cloning stores the new provider voice ID first and then dispatches
`DeleteReplacedProviderVoice` to delete the replaced provider asset with three
attempts. Permanent cleanup failure is logged and reported to administrators.

## Monthly Periods and Analytics

`subscription_usage_periods` stores immutable monthly plan and limit snapshots.
`subscription_limits` points to the active period and is reset monthly.
`subscription_uses` points to the period in which the operation was reserved.

`GET /api/usage` accepts:

- `from=YYYY-MM-DD`
- `to=YYYY-MM-DD`
- `group_by=day|month`
- `timezone=IANA timezone`, for example `America/Bogota`

Date boundaries and day/month buckets are interpreted in the requested
timezone and converted to UTC for storage queries. The admin sends the browser
timezone so activity near UTC midnight remains on the user's local date. The
range cannot exceed 24 months. The response separates plan-covered metrics,
credit-covered metrics, consumed credits, reserved credits, purchases,
reversals, period snapshots, and per-service series. It remains available after
subscription expiration so users can inspect history and the dormant wallet
balance.

## Reservation Recovery

Usage states are:

- `reserved`: plan capacity and wallet units are held.
- `finalized`: the operation or paid provider attempt was consumed.
- `released`: the operation did not consume the provider and capacity was
  restored.

The scheduler runs:

```sh
php artisan subscriptions:release-stale-usage-reservations
```

every ten minutes. Reservations older than 60 minutes are released by default.
Change the threshold with
`SUBSCRIPTION_USAGE_RESERVATION_TTL_MINUTES`.

## Runtime Requirements

Production requires at least one queue worker and exactly one effective
scheduler replica.

| Schedule | Command |
| --- | --- |
| Every minute | `subscriptions:expire-ended` |
| Every 10 minutes | `subscriptions:release-stale-usage-reservations` |
| Every five minutes | `subscriptions:bill-recurring` |
| Daily at 00:05 | `subscriptions:renew-free` |
| Daily at 00:10 | `subscriptions:reset-usage-limits` |

Web, queue, and scheduler processes must use the same release, database, cache,
queue, and application key. Distributed schedule locks must use a shared cache.
The database queue uses `after_commit=true`; `retry_after` must remain greater
than the worker timeout.

## Observability

Track:

- credit grants, reservations, consumption, releases, reversals, and debt;
- idempotency conflicts;
- reserved usage older than the configured TTL;
- Wompi pending age and duplicate webhook events;
- provider errors after quota reservation;
- queue lag, failed jobs, scheduler health, and replacement voice cleanup;
- provider-cost assumptions and gross margin by service.

Do not log card data, raw visitor audio, voice samples, access tokens, or
provider secrets.

## Deployment Checklist

These instructions do not deploy.

1. Recheck provider prices and the 40% variable-cost target.
2. Build and test the exact release.
3. Run `php artisan migrate --force` once.
4. Start web, queue workers, and exactly one scheduler.
5. Confirm `php artisan schedule:list`.
6. Confirm queue `retry_after`, worker timeout, and `after_commit`.
7. Verify Wompi approval, pending, duplicate, decline, and reversal in sandbox.
8. Verify plan-first allocation, all exhausted metrics, monthly reset, and
   dormant wallet behavior.
9. Verify Billing, Usage, public plans, Terms, Privacy, and Data Deletion in ES
   and EN on desktop and mobile.
