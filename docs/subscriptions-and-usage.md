# Subscriptions, Usage, and Public Messaging

This document describes the current Bigmelo subscription model and the runtime
controls that protect paid AI and audio operations.

## Starter Plans

Both Starter plans include the same limits per monthly usage period:

| Capability | Included |
| --- | ---: |
| Published profiles | 1 |
| Avatar images | 1 |
| Avatar video | 5 seconds |
| Authorized voice clones | 1 |
| Visitor messages, text or audio | 1,000 |
| Incoming audio messages | 500 |
| Maximum duration per incoming audio | 30 seconds |
| Incoming audio duration | 15,000 seconds |
| Text converted to response audio | 20,000 characters |
| Credits | 1,000 |
| Products per profile | 15 |
| Selected Instagram media | 10 |
| Selected TikTok media | 10 |
| Selected OnlyFans media | 10 |
| Public social links | Enabled |

- Monthly Starter costs USD 12.99 per month.
- Annual Starter costs USD 129 per year.
- The annual plan is billed annually, but its usage limits reset monthly.
- Unused monthly capacity does not roll over.
- Credits are allocated as 500 for up to 1,000 chat messages and 500 for up to
  20,000 TTS characters.

The 7-day trial uses reduced limits: 100 visitor messages, 25 incoming audio
messages, 750 incoming audio seconds, 2,000 TTS characters, and 100 credits.

The canonical plan configuration is `src/config/subscriptions.php`. The public
site reads `GET /api/subscription/public-plans`, while the authenticated admin
reads `GET /api/subscription/plans`.

Products and selected integration media are enforced through
`SubscriptionPlanCapabilityService`. A profile with an active subscription uses
that plan's capabilities; legacy local profiles without a subscription fall
back to the configured default plan.

## Visitor Message Flow

### Text

1. The API validates the profile and optional chat.
2. `SubscriptionUsageRecorder::reserve` locks the user and current limit row.
3. One `chat_messages` unit and its credit allocation are deducted atomically.
4. The question is stored and the usage reservation is finalized.
5. `MessageStored` starts answer generation.

If quota is unavailable, the request is rejected before persistence and before
OpenAI is called.

### Incoming audio

1. The upload is validated with a 10 MB maximum.
2. `AudioMessageInspector` reads the real media duration with getID3.
3. Unknown durations and recordings over 30 seconds are rejected before
   transcription.
4. A single atomic reservation deducts one visitor message, one incoming audio,
   the measured incoming-audio seconds, and the corresponding credits.
5. OpenAI Whisper transcribes the recording.
6. Once transcription was attempted, incoming-audio usage is finalized even if
   transcription fails because the paid provider operation was consumed.
7. If processing fails before the provider attempt, the reservation is released.

The message endpoints are rate limited by IP and profile. The default is 20
requests per minute and can be changed with
`SUBSCRIPTION_MESSAGE_RATE_LIMIT_PER_MINUTE`.

## Generated Responses and TTS

Generated answers are limited to 400 characters. `VoiceService` creates a unique
reservation for every TTS request, including repeated identical text.

- TTS quota is reserved before ElevenLabs is called.
- Successful audio generation finalizes the reservation.
- Provider exceptions or failed provider responses release the reservation.
- If TTS quota is exhausted, `AnswerBuilder` keeps the generated text response
  and omits audio. Visitor text and incoming audio remain available while their
  own limits remain.

## Public Messaging Capabilities

`ProfileMessagingCapabilitiesService` exposes the current server decision in
profile responses and through:

```text
GET /api/profile/{profile}/messaging-capabilities
```

Response fields:

- `text_messages_enabled`
- `audio_messages_enabled`
- `audio_max_duration_seconds`
- `reason`

The public profile uses this contract to disable its text input or microphone.
It refreshes the state when the browser regains focus and after message
responses. TTS exhaustion is deliberately not an input capability, because
responses can continue as text.

Stable quota and audio error codes include:

- `SUBSCRIPTION_INACTIVE`
- `SUBSCRIPTION_LIMIT_REACHED`
- `CHAT_MESSAGE_LIMIT_REACHED`
- `AUDIO_MESSAGE_LIMIT_REACHED`
- `TTS_CHARACTER_LIMIT_REACHED`
- `AUDIO_DURATION_UNKNOWN`
- `AUDIO_DURATION_EXCEEDED`
- `AUDIO_TRANSCRIPTION_FAILED`
- `AUDIO_TRANSCRIPTION_EMPTY`

## Reservation Lifecycle

`subscription_uses.status` has three states:

- `reserved`: capacity has been deducted before a provider call.
- `finalized`: the operation was accepted or the paid provider attempt occurred.
- `released`: the operation did not consume the provider and capacity was
  restored.

The idempotency key prevents the same logical operation from being deducted
twice. Database transactions and row locks prevent two concurrent requests from
both consuming the last available unit.

Credit balances and usage are stored with six decimal places so small TTS
operations do not accumulate two-decimal rounding drift. Reusing a non-released
idempotency key with a different user, profile, usage type, or amount is
rejected and logged.

Interrupted processes can leave a reservation pending. The scheduler runs:

```sh
php artisan subscriptions:release-stale-usage-reservations
```

every ten minutes. By default, reservations older than 60 minutes are released.
Change that threshold with `SUBSCRIPTION_USAGE_RESERVATION_TTL_MINUTES`.

## Billing and Period Operations

The production scheduler must have exactly one active replica. Relevant
commands are:

| Schedule | Command |
| --- | --- |
| Every minute | `subscriptions:expire-ended` |
| Every 10 minutes | `subscriptions:release-stale-usage-reservations` |
| Hourly | `subscriptions:bill-recurring` |
| Daily at 00:05 | `subscriptions:renew-free` |
| Daily at 00:10 | `subscriptions:reset-usage-limits` |

At least one queue worker must also run for message processing, AI generation,
voice operations, notifications, and billing jobs. Web, worker, and scheduler
must use the same release, database, cache, queue, and application key.
Critical schedules use distributed single-server and overlap locks. The
database queue uses `after_commit=true`; its 360-second `retry_after` must remain
greater than the worker's 300-second timeout.

## Observability

The API logs reservation, finalization, and release transitions with the
idempotency key, subscription usage identifier, profile, user, and usage type.
Audio rejections log duration, configured maximum, profile, file size, and
failure reason without logging the recording contents.

Track these conditions in production:

- growth in `reserved` rows older than the configured TTL;
- repeated `AUDIO_DURATION_UNKNOWN` responses;
- quota rejection rates by error code and profile;
- queue lag and failed jobs;
- provider failures after quota reservation;
- scheduler and recurring billing command health.

## Deployment Checklist

No deployment is performed by these instructions. Before a production release:

1. Build and test the exact release image.
2. Run `php artisan migrate --force` once.
3. Start the web process, at least one queue worker, and exactly one scheduler.
4. Confirm `php artisan schedule:list` includes stale reservation release,
   expiration, billing, and monthly limit reset.
5. Confirm worker and scheduler logs use the new release.
6. Confirm queue `retry_after` exceeds `--timeout` and `after_commit` is enabled.
7. Exercise text, audio, TTS fallback, quota rejection, and monthly reset in a
   non-production environment.
8. Verify the public plan page, admin billing page, usage page, Terms, Privacy,
   and Data Deletion pages in Spanish and English.
