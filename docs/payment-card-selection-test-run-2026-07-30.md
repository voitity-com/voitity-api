# Payment Card Selection Test Run

Date: 2026-07-30  
Environment: local API, local administrator, Wompi Sandbox  
Scope: saved-card selection, add-card continuity, credit purchases, declines,
localization, responsive behavior, and payment operations

## Test Account

The run used a dedicated local user with an active paid Starter subscription,
an active profile, purchased-credit wallet data, and multiple saved cards. Test
records were isolated from production and no deployment was performed.

## Cycle 1: Saved Card Selection

Validated that:

- The chargeable default card is preselected.
- A chargeable secondary card can be selected immediately before payment.
- Rejected or unavailable cards remain visible but disabled.
- The API rejects a disabled, rejected, expired, or foreign source.
- A secondary one-time charge does not change the renewal default.

Finding: opening Add Card while Buy Credits was still exiting briefly left two
dialogs in the DOM.

Adjustment: Add Card now opens from the Buy Credits transition `onExited`
callback, guaranteeing one active dialog at a time.

## Cycle 2: Add Card From Buy Credits

Added a real Wompi Sandbox `4242` card from the credit-purchase flow.

Validated that:

- Buy Credits closes before Add Card appears.
- Saving the card returns to Buy Credits instead of changing page.
- The new card is selected automatically.
- The requested credit quantity and accepted terms remain unchanged.
- Canceling Add Card also returns to the original purchase flow.

Finding: Wompi's generic provider type was displayed as the card brand.

Adjustment: metadata brand now has priority over the generic `CARD` type in the
API and the browser token brand/name is persisted consistently.

## Cycle 3: Approved Credit Purchase

Purchased 1,000 credits with the real Wompi Sandbox approved card.

Validated that:

- Wompi approved the charge.
- The order stored the exact selected local payment-source ID.
- The wallet increased exactly once.
- The purchase appeared in history.
- The modal closed only after the approved result.
- Refreshing did not duplicate the wallet grant.

No product adjustment was required.

## Cycle 4: Declined Credit Purchase

Added the real Wompi Sandbox `4111` card from Buy Credits and attempted a 2,000
credit purchase.

Validated that:

- Draft quantity and accepted terms survived the add-card transition.
- The declined order granted no credits.
- The wallet balance remained unchanged.
- Only the attempted card became `requires_attention`.
- The rejected card became disabled in the selector.
- Another valid saved card was selected automatically.
- The purchase modal remained open for recovery.

Finding: the initial dialog error used the API's technical English message.

Adjustment: `CREDIT_PAYMENT_DECLINED` is translated inside the purchase dialog.

## Cycle 5: Localization and UI Stability

Validated English and Spanish labels, card statuses, amount/date formats, terms
links, Wompi link, and language switching with the Buy Credits flow.

Finding: changing language triggered a full billing reload and could leave a
toast in the previous language.

Adjustment: billing loading now uses a stable translation reference and the
language switch dismisses existing toasts before showing the new-language
confirmation.

## Cycle 6: Regression and Operations

Validated:

- API ownership, chargeability, idempotency, and default-card invariants.
- Payment-method brand normalization.
- Scheduler and queue heartbeat health behavior.
- The payment schedule includes the one-minute operations heartbeat and
  five-minute recurring billing command.
- TypeScript type checking, ESLint, and the production Vite build.
- Desktop card-selection layout and the existing mobile-responsive layout.

Finding: the production build retained a large shared application chunk.

Adjustment: heavy vendor dependencies are split into stable map, PDF, syntax
highlighting, calendar, chart, editor, MUI, cloud-auth, and React chunks.

## Provider Coverage

Real Wompi Sandbox coverage completed:

| Expected result | Card number | Expiration | CVC | Cardholder |
| --- | --- | --- | --- | --- |
| Approved (`APPROVED`) | `4242 4242 4242 4242` | Any future month and year | Any three digits, such as `123` | Any valid name with at least five characters |
| Declined (`DECLINED`) | `4111 1111 1111 1111` | Any future month and year | Any three digits, such as `123` | Any valid name with at least five characters |

The Wompi environment must be Sandbox and the public commerce key must start
with `pub_test_`. Any other card number produces a final `ERROR` status. These
test values must not be used in production.

Automated signed-webhook coverage includes pending, provider error, duplicate
delivery, stale status transitions, approval after pending, and reversal.
Wompi does not publish distinct Sandbox cards for insufficient funds or expired
cards, so those exact issuer reasons remain provider-assisted acceptance tests.

## Result

All six cycles completed without a production deployment. The final flow keeps
the user in context, prevents overlapping dialogs, supports explicit selection
of any chargeable saved card, blocks errored cards, preserves renewal defaults,
and exposes an external payment-operations health signal.
