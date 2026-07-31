# Payment Processing and Saved Payment Methods

This document is the operating contract for Bigmelo payment methods, subscription
payments, trial conversion, recurring billing, purchased credits, Wompi
webhooks, stored data, and payment security.

## System Boundaries

- Bigmelo displays plan and credit prices in USD.
- Wompi processes charges in COP using the USD/COP rate captured on each
  `payment_orders` record.
- Wompi, not Bigmelo, receives the card PAN and CVC.
- The browser tokenizes the card directly against Wompi. Bigmelo receives a
  short-lived card token and exchanges it for a reusable Wompi payment source.
- Bigmelo stores only card display data plus an encrypted reusable provider
  identifier.
- A Wompi approval is the authority for activating a paid subscription or
  granting purchased credits.

The plan, trial, credit, currency, and provider configuration lives in:

- `src/config/subscriptions.php`
- `src/config/payment.php`
- `src/.env`

## Stored Payment Data

Each `payment_sources` record belongs to one user and stores:

| Field | Purpose |
| --- | --- |
| `provider_source_ciphertext` | Encrypted Wompi reusable payment source ID |
| `provider_source_hash` | One-way lookup and uniqueness hash |
| `card_brand` | Display-only card brand |
| `card_last_four` | Display-only last four digits |
| `card_exp_month`, `card_exp_year` | Display and local expiration checks |
| `status`, `reusable`, `verified_at` | Chargeability state |
| `is_default` | Account payment method used for future automatic charges |
| `requires_attention` | Blocks user-initiated charges after a provider decline |
| `last_payment_failure_code` | Sanitized reason for the current attention state |
| `last_payment_failed_at` | Time of the most recent provider decline |
| `last_failed_payment_order_id` | Local order that caused the attention state |
| `last_used_at` | Last attempted charge time |
| `disabled_at` | Local soft-removal time |
| `provider_synced_at` | Last provider registration or webhook sync |

The model hides provider identifiers, hashes, ciphertext, and internal metadata
from serialization. API responses use `PaymentMethodResponse`, which returns
only the local ID, provider/type, display data, status, expiration,
chargeability, default state, attention state, sanitized last-failure data, and
timestamps.

Bigmelo must never store or log:

- Full card numbers
- CVC/CVV values
- Raw card tokenization payloads
- Wompi private keys
- Wompi integrity or event secrets
- Acceptance tokens after they have fulfilled their request purpose

## Payment Method API

All routes require Sanctum authentication and are rate limited by
`payment-method-management`.

| Method | Route | Ability | Behavior |
| --- | --- | --- | --- |
| `GET` | `/api/payment-methods/setup` | `payments:create` | Returns Wompi public tokenization configuration and current acceptance agreements |
| `POST` | `/api/payment-methods` | `payments:create` | Exchanges a one-time card token for a reusable source |
| `GET` | `/api/payment-methods` | `payments:read` | Lists enabled sanitized methods |
| `PATCH` | `/api/payment-methods/{id}/default` | `payments:create` | Selects the account default method |
| `DELETE` | `/api/payment-methods/{id}` | `payments:create` | Soft-disables a secondary method |
| `GET` | `/api/subscription/billing-state` | `payments:read` | Returns active or recoverable subscription billing state |
| `POST` | `/api/subscription/renewal/retry` | `payments:create` | Immediately retries a failed renewal |

The maximum enabled methods per user defaults to five and is configured by
`PAYMENTS_MAXIMUM_METHODS_PER_USER`.

### Adding a Card

1. The client calls `GET /api/payment-methods/setup`.
2. The client accepts the Wompi terms and personal data authorization.
3. The browser sends PAN, CVC, expiration, and cardholder name directly to the
   Wompi tokenization endpoint.
4. Wompi returns a one-time token.
5. The client sends that token and the current acceptance tokens to
   `POST /api/payment-methods`.
6. The API creates a reusable Wompi payment source with the authenticated
   user's email.
7. Only a Wompi source normalized as active and reusable is stored.
8. The provider source ID is encrypted before database persistence.
9. The first chargeable method becomes the default method.
10. Sending `make_default: true` atomically selects the new method for active
    and payment-recovery subscriptions.

Card registration is serialized per user with a distributed cache lock. The
database transaction locks the user row before counting enabled methods. It
does not apply `FOR UPDATE` to an aggregate query, which PostgreSQL rejects.

Card form state is cleared immediately after tokenization, whether the API
registration succeeds or fails.

### Default Method Rules

- Every account with enabled methods must have one default method.
- Selecting a default requires ownership, enabled state, active provider state,
  reusable state, a decryptable provider ID, and a non-expired card.
- Selecting a new default clears the prior default in the same database
  transaction.
- Active and payment-recovery recurring subscriptions are synchronized to the
  new default.
- Trial conversion, subscription renewal, and credit purchases resolve the
  account default at charge time. They do not trust a stale subscription
  pointer.
- An approved initial subscription payment makes the method used by that
  payment the default.
- A Wompi `DECLINED` response marks the method as requiring attention. It stays
  visible for audit and replacement, but user-initiated plan and credit charges
  cannot use it.
- `PENDING`, provider transport failures, and local `ERROR` outcomes do not
  mark the card as rejected because no issuer decline was confirmed.
- A later approved charge clears the attention state.
- Automatic subscription retries may retry the technically reusable default
  source at the configured schedule. Manual renewal and credit purchases
  require a chargeable default source without an attention flag.

### Removing a Card

- The default method cannot be removed.
- The user must select another default method first.
- A method linked to a pending payment cannot be removed.
- Removal is a local soft disable so historical payment records remain
  auditable.
- The current Wompi integration does not expose provider-side source
  revocation. This limitation must be revalidated against the provider before
  production launch and whenever Wompi changes its payment-source API.

## Subscription Payment Flows

### New Paid Subscription with a Saved Method

`POST /api/subscription/payment-source` accepts `plan`,
`payment_source_id`, and accepted terms.

1. The API validates ownership and chargeability.
2. It records a pending `subscription_initial` payment order with the USD
   display price, captured exchange rate, COP amount, terms version, and
   accepted price.
3. The API charges the provider source through Wompi.
4. A short pending poll is attempted when Wompi returns `PENDING`.
5. `APPROVED` activates the subscription and its first monthly usage period.
6. `PENDING` leaves the order pending for webhook resolution.
7. `DECLINED` leaves the user without a new active subscription and marks the
   source as requiring replacement for user-initiated charges.
8. `ERROR` or `VOIDED` leaves the user without a new active subscription but
   does not misclassify the method as issuer-declined.

The same endpoint can still accept a newly tokenized payment source payload for
backward compatibility. The administrator UI uses the saved-method flow after
registering a new card.

### Wompi Web Checkout

`POST /api/payments/wompi/checkout` is an alternative hosted checkout flow.
It creates a pending order and returns a Wompi checkout URL/widget
configuration. The subscription is activated only after a valid approved
webhook. When the approved event includes a reusable `payment_source_id`, that
source is stored and made default.

### Seven-Day Trial

`POST /api/subscription/trial` accepts an active saved method or a newly
tokenized method.

- Wompi must first confirm the reusable source as active.
- The local trial setup order is recorded as a zero-value approved order.
- The trial subscription starts immediately and the selected method becomes
  default.
- `trial_ends_at`, `renews_at`, and `next_billing_at` identify the first
  conversion attempt.
- Cancelling the trial sets `cancel_at_period_end`; access remains available
  through the trial end and no conversion charge is attempted.
- If conversion is due and the default method is missing, disabled, expired, or
  otherwise unchargeable, the trial enters payment recovery.
- If Wompi rejects the conversion, the trial is marked past due, made inactive,
  and its active profiles are suspended immediately.

No system can confirm card balance when a card is saved. Available balance and
issuer authorization are known only when Wompi submits an actual charge.

### Recurring Renewal

The scheduler scans due subscriptions every five minutes with overlap
protection and a single-server lock. Retry timestamps stored in the database
prevent a five-minute scan from creating repeated charges.

1. Due active recurring subscriptions that are not cancelled are selected.
2. Recoverable past-due subscriptions are selected only when
   `next_payment_retry_at` is due.
3. A pending order for the source subscription and billing cycle is reused
   instead of creating a duplicate charge, regardless of which card it used.
4. The current plan price and exchange rate are captured.
5. Wompi is charged.
6. An approval creates the renewed subscription period and a new monthly usage
   period, then restores previously active profiles up to the new plan limit.
7. A pending response waits for provider resolution.
8. A rejection records the failed order, suspends subscription access and
   profiles, marks the source as requiring attention, schedules the next retry,
   and sends a failure notification.

The automatic attempt sequence is:

| Attempt | Timing |
| --- | --- |
| 1 | At `next_billing_at` or `renews_at` |
| 2 | 6 hours after attempt 1 fails |
| 3 | 24 hours after attempt 2 fails |
| 4 | 72 hours after attempt 3 fails |

After attempt 4, `next_payment_retry_at` is null and no further automatic
charge is made. Manual recovery remains available.

The source `subscriptions` record stores:

- `payment_failure_code`
- `payment_failed_at`
- `payment_retry_count`
- `next_payment_retry_at`
- `last_failed_payment_order_id`
- `access_ended_reason`

Renewal `payment_orders` store `source_subscription_id`,
`billing_cycle_at`, and `attempt_number`. This identity prevents a new charge
when an earlier card still has a pending transaction for the same cycle.

### Manual Payment Recovery

1. The administrator loads `GET /api/subscription/billing-state`.
2. Recovery controls are shown only for an inactive `past_due` subscription
   whose `access_ended_reason` is `payment_failure`.
3. The user adds or selects a chargeable default card.
4. The administrator calls `POST /api/subscription/renewal/retry`.
5. An existing pending order returns `202` and blocks a duplicate charge.
6. An approved charge creates the new period and restores profiles up to the
   plan profile limit.
7. A declined charge remains recoverable and updates the retry schedule.

The billing state sets `payment_recovery.can_retry_now` to `false` while the
default card requires attention. The administrator shows the declined card,
directs the user to add or select another method, and enables immediate renewal
only after a valid replacement becomes default.

There is no automatic fallback across secondary cards. Charging multiple cards
without explicit user consent can create duplicate or unexpected charges. The
user must choose a new default and retry through the supported billing flow.

## Purchased Credit Payments

`POST /api/credits/purchases` requires:

- An active paid subscription
- A chargeable saved payment method
- A valid credit quantity
- A unique idempotency key
- Accepted terms

The client may send `payment_source_id` to charge a specific saved method. If it
is omitted, the API uses the chargeable account default. The selected source
must belong to the authenticated user, be enabled, reusable, verified, and not
require attention. A secondary source selected for one credit purchase does not
become the subscription renewal default.

The API creates a one-time `credit_purchase` order and charges the selected
method. Credits are granted only after approval. Reusing the same idempotency
key with identical data returns the existing result; reusing it with a
different amount or payment source is rejected. A later provider reversal
creates the corresponding wallet debit exactly once.

Admin grants, one-time subscriptions, and trials are not treated as paid
recurring subscriptions for credit purchases. A missing or unchargeable
default method returns the structured code `PAYMENT_METHOD_REQUIRED`; clients
must offer the user an add/change-card action.

A provider decline returns `CREDIT_PAYMENT_DECLINED`, grants no wallet entry,
and marks only the attempted method as requiring attention. Reusing that method
for another credit purchase is blocked while other chargeable methods remain
available. `PENDING` returns
`CREDIT_PURCHASE_PENDING`; provider errors return `CREDIT_PAYMENT_FAILED`.
Neither status grants credits or rejects the saved method.

Purchased credit details and consumption rules are documented in
[`subscriptions-and-usage.md`](subscriptions-and-usage.md).

## Webhooks and Idempotency

`POST /api/payments/wompi/events` is unauthenticated because Wompi calls it, but
every event must pass the Wompi checksum validation.

The handler:

1. Stores a sanitized event snapshot.
2. Acknowledges duplicate provider event IDs without processing twice.
3. Rejects unknown references and amount/currency mismatches.
4. Locks the local payment order.
5. Maps the provider status to the local order status.
6. Upserts the reusable source without exposing its provider ID.
7. Activates subscriptions or grants/reverses credits exactly once.
8. Records `processed_at` and emits structured diagnostics.

The local transition guard prevents stale provider events from degrading an
approved order. `PENDING` can move to a terminal state, a failed terminal state
can be corrected to `APPROVED`, and `APPROVED` can move only to `VOIDED`.
`VOIDED` remains terminal. This preserves legitimate reversals without allowing
a late `DECLINED`, `ERROR`, `EXPIRED`, or `PENDING` event to revoke an already
approved payment.

Raw provider payloads are not persisted. `PaymentPayloadSanitizer` allowlists
only operational fields such as transaction ID, reference, status, amount,
currency, event type, and provider status.

## Payment Operations Monitoring

The scheduler runs `payments:heartbeat` every minute. The command records a
scheduler heartbeat and dispatches `RecordPaymentQueueHeartbeat`; the queued
job records a separate queue-worker heartbeat.

`GET /api/health/payments` returns:

- HTTP 200 when scheduler and queue heartbeats are fresh.
- HTTP 503 when either heartbeat is missing or stale.
- The last valid Wompi webhook timestamp as operational context.

The webhook timestamp is informational because an account can legitimately
receive no payment events for an extended period. It does not make the endpoint
unhealthy by itself. The endpoint exposes no payment identifiers, card data, or
provider secrets.

Production must run both the Laravel scheduler and queue workers. An external
uptime monitor should call `/api/health/payments`; a check running only inside
the scheduler cannot detect that the scheduler itself stopped. Default stale
thresholds are three minutes for the scheduler and five minutes for the queue
and can be configured with `PAYMENTS_SCHEDULER_STALE_AFTER_SECONDS` and
`PAYMENTS_QUEUE_STALE_AFTER_SECONDS`.

### Wompi Sandbox Test Cards

Before entering test-card data, verify that the Wompi environment is Sandbox
and that the public commerce key starts with `pub_test_`.

| Expected result | Card number | Expiration | CVC |
| --- | --- | --- | --- |
| Approved (`APPROVED`) | `4242 4242 4242 4242` | Any future month and year | Any three digits, for example `123` |
| Declined (`DECLINED`) | `4111 1111 1111 1111` | Any future month and year | Any three digits, for example `123` |

Use a valid cardholder name of at least five characters, for example
`Sandbox User`. Any other card number finishes with provider status `ERROR`.
These values are exclusively for Wompi Sandbox and must never be used as
production payment data.

Wompi does not provide separate official Sandbox card numbers for insufficient
funds or an expired card. Those provider-specific failure reasons therefore
require Wompi-assisted acceptance testing or a controlled test double; the
local contract tests still cover decline, error, pending, duplicate webhook,
stale event, and reversal behavior.

## Security Controls

- Card tokenization occurs in the browser against Wompi.
- Provider source IDs are encrypted with Laravel `APP_KEY`.
- Deterministic SHA-256 hashes support lookup without plaintext storage.
- API responses are explicitly allowlisted.
- Authorization checks enforce user ownership.
- Sanctum abilities separate payment reads and writes.
- Server API tokens expire according to
  `SANCTUM_TOKEN_EXPIRATION_MINUTES` (default: 1,440 minutes).
- Payment-method management is rate limited.
- API security headers deny framing, MIME sniffing, referrer disclosure, and
  unnecessary browser permissions.
- Logs contain local IDs and state transitions, never card tokens, provider
  secrets, PAN, or CVC.
- Analytics are disabled on checkout, billing, and payment-method routes in the
  administrator UI.

Production must use HTTPS, a protected `APP_KEY`, separate Wompi production
credentials, secret rotation procedures, restricted database/log access, and
backups that preserve encrypted payment identifiers.

## Structured Logs

The payment flows emit searchable records for:

- Payment method listing, registration, default changes, and local removal
- Rejected payment-method operations
- Initial subscription payment outcomes
- Trial start, cancellation, and reactivation
- Recurring subscription payment outcomes
- Payment recovery scheduling, manual retry requests, duplicate pending
  blocks, access suspension, and profile restoration
- Credit purchase outcomes
- Checkout creation
- Duplicate, ignored, mismatched, and processed Wompi events
- Rejected-card attention changes, approved-card recovery, and ignored stale
  provider status transitions

Use local entity IDs (`user_id`, `payment_source_id`, `payment_order_id`,
`subscription_id`) and status fields for correlation.

## Operations and Production Checklist

Required long-running processes:

- One scheduler process
- At least one queue worker
- The web/API process

Required scheduled payment commands:

| Command | Schedule |
| --- | --- |
| `subscriptions:bill-recurring` | Every five minutes |
| `subscriptions:expire-ended` | Every minute |
| `subscriptions:reset-usage-limits` | Daily |

Before production:

1. Configure production Wompi public/private/integrity/event credentials.
2. Configure and verify the Wompi event URL over HTTPS.
3. Confirm the production USD/COP exchange-rate strategy and acceptable bounds.
4. Back up and protect `APP_KEY`; losing it makes encrypted source IDs
   unusable.
5. Run migrations and verify every legacy source was encrypted and a single
   default was selected per user.
6. Confirm scheduler and queue health, overlap locks, and alerting.
7. Test approved, pending, declined, insufficient-funds, expired-card, invalid
   checksum, duplicate-event, and reversal scenarios in Wompi Sandbox.
8. Confirm production log retention and redaction.
9. Recheck whether Wompi supports provider-side payment-source revocation.
10. Verify the production frontend and edge Content Security Policy permits
    only the required Bigmelo and Wompi origins.

## Test and Verification Commands

```sh
docker compose exec -T app php artisan test \
  tests/Feature/Http/Controllers/api/v1/PaymentMethodControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/PaymentControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/CreditControllerTest.php \
  tests/Unit/Classes/Subscriptions/SubscriptionBillingServiceTest.php \
  tests/Unit/Classes/PaymentService/PaymentPayloadSanitizerTest.php
```

Run the complete suite before release:

```sh
docker compose exec -T app php artisan test
```

The Postman collection is
`postman/voitity-api.postman_collection.json`. Run `Payment Methods / Get
Payment Method Setup`, tokenize a Wompi Sandbox card outside Postman, set
`payment_source_token`, and then run `Add Payment Method`. Never add test or
production PAN/CVC values to the collection.

The six-cycle functional and visual regression record is
[`payment-recovery-test-run-2026-07-30.md`](payment-recovery-test-run-2026-07-30.md).
