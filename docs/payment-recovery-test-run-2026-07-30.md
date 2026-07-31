# Payment Recovery Regression Run - 2026-07-30

This record documents the six payment, credit, renewal, and saved-card
verification cycles completed against the local Bigmelo API and administrator.
It is intended as a reproducible regression reference, not as evidence of a
production Wompi charge.

## Environment

- API: `http://localhost:8000`
- Administrator: `http://localhost:3000`
- Database: local PostgreSQL
- Automated tests: isolated SQLite in-memory database
- Provider behavior: Wompi-compatible HTTP fakes and signed webhook fixtures
- Visual user: `payment-cycle-test@bigmelo.local`
- Desktop viewport: 1440 x 1000
- Mobile viewport: 390 x 844
- Languages: English and Spanish

No production deployment or production payment was performed.

## Cycle Results

### Cycle 1 - Approved Baseline

- Started a paid Starter subscription with a saved reusable method.
- Purchased credits after plan activation.
- Renewed the subscription through recurring billing.
- Verified that plan limits renew before purchased credits are consumed.

Result: passed. No adjustment was required.

### Cycle 2 - Provider Decline Tracking

- Declined initial plan, credit, renewal, and trial-conversion charges.
- Verified that no subscription or credits were granted.
- Persisted a sanitized attention state on the attempted payment method.
- Corrected PostgreSQL compatibility by avoiding `FOR UPDATE` on aggregate
  method-count queries.
- Separated user chargeability from scheduler retryability.

Result: passed after the database portability and retry-policy adjustments.

### Cycle 3 - Credit Blocking and Card Replacement

- Blocked a second credit purchase after a confirmed card decline.
- Returned `CREDIT_PAYMENT_DECLINED` for the declined transaction.
- Returned `PAYMENT_METHOD_REQUIRED` for later attempts with the rejected
  default method.
- Added a replacement default method and approved a new credit purchase.
- Preserved the old method's attention state and granted credits exactly once.

Result: passed.

### Cycle 4 - Failed Renewal Recovery

- Suspended subscription access and active profiles after a failed renewal.
- Exposed the rejected default card through the billing-state API.
- Kept `can_retry_now` false until a chargeable replacement became default.
- Approved an immediate manual renewal with the replacement method.
- Restored only the profiles allowed by the renewed plan.

Result: passed.

### Cycle 5 - Pending, Error, Webhook, and Idempotency Cases

- Confirmed that `PENDING` and provider errors grant no credits and do not
  reject a card.
- Confirmed duplicate requests and provider events do not duplicate charges,
  ledger entries, subscriptions, or usage.
- Confirmed `VOIDED` reversals create the expected wallet debit exactly once.
- Prevented stale declined events from degrading approved orders.
- Allowed a later approval to recover a previously failed order once.
- Verified recurring jobs, stale usage-reservation cleanup, and scheduler
  overlap protection.

Result: passed after adding the webhook transition guard.

### Cycle 6 - Functional and Visual Administrator Flow

- Signed in with a local user and opened Billing through the administrator.
- Verified the top purchased-credit balance and scroll shortcut.
- Verified the charged-card label, USD-only price, and official Wompi link.
- Cancelled automatic renewal through the confirmation dialog.
- Verified the profile-deactivation message and reactivated without a dialog.
- Verified rejected-card badges, replacement guidance, default-card rules, and
  removal restrictions.
- Added a replacement method through the real PostgreSQL service path.
- Verified that Renew is disabled with the rejected default and enabled with
  the valid replacement.
- Verified English and Spanish copy at desktop and mobile sizes.
- Detected and removed nested Buy Credits and Add Card dialogs.

Result: passed after the single-dialog accessibility adjustment.

## Automated Commands

Focused payment regression:

```sh
docker compose exec -T app php artisan test \
  tests/Feature/Http/Controllers/api/v1/PaymentControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/PaymentMethodControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/CreditControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/SubscriptionActionsControllerTest.php \
  tests/Unit/Classes/Subscriptions/SubscriptionBillingServiceTest.php
```

Queue and usage regression:

```sh
docker compose exec -T app php artisan test \
  tests/Feature/Console/RecurringBillingCommandTest.php \
  tests/Feature/Console/SubscriptionUsageReservationCommandTest.php \
  tests/Unit/Jobs \
  tests/Unit/Classes/Subscriptions/SubscriptionUsageRecorderTest.php
```

Full release regression:

```sh
docker compose exec -T app php artisan test
docker compose exec -T app php artisan schedule:list
```

Administrator checks:

```sh
cd ../voitity-admin/src
npm run typecheck
npm run lint
npm run build
```

## Expected Recovery Contract

1. A confirmed Wompi decline marks only the attempted method as requiring
   attention.
2. Credit purchases and manual renewal do not use a rejected method.
3. Automatic retries follow the configured 6, 24, and 72 hour schedule while
   the source remains technically reusable.
4. The user adds or selects a valid replacement and makes it default.
5. The subscription pointer follows the new default.
6. Manual renewal becomes available immediately.
7. Approval creates a new billing period, clears failure state on the approved
   method, and restores allowed profiles.
8. Purchased credits remain persistent and are consumed only after the
   corresponding renewed plan limit is exhausted.

## Remaining External Validation

Before production launch, repeat the provider scenarios with official Wompi
Sandbox test cards for insufficient funds, expired card, generic decline,
pending-to-approved, and reversal. Confirm production scheduler, queue, webhook
HTTPS, secret rotation, log retention, and alerting separately.
