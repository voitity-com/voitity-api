# Bigmelo Profile Insights

This document is the measurement contract for public profile activity. It defines what is recorded, how every value is calculated, and how to extend the system without changing the meaning of existing reports.

## Time and scope

- Every metric belongs to one `profile_id`.
- Reports receive calendar dates plus an IANA timezone. Dates are converted to an inclusive UTC interval before querying.
- The default range is the current date minus one calendar month through the current date.
- Chats are attributed by `started_at`; messages by `messages.created_at`; interactions by `occurred_at`; classifications by the chat start date.
- A unique visitor is a pseudonymous browser/device identifier, not a verified person.

## Chat lifecycle

- A chat starts when the first visitor message is accepted without a currently active chat.
- An open chat remains active while its inactivity is less than `CHAT_INACTIVITY_MINUTES` (30 by default).
- Every accepted visitor message updates `last_activity_at`.
- A scheduled closer marks inactive chats as closed. `ended_at` is the last activity plus the configured timeout, rather than the time the scheduler happened to run.
- A message sent with a closed or inactive chat creates a new chat and is processed immediately in that new chat.
- Chat closure triggers asynchronous conversation classification when `CONVERSATION_INSIGHTS_ENABLED=true`. The local demo keeps it disabled and uses deterministic classified seed data so tests never send transcripts to an external provider.

## Metric contract

| Metric | Source | Formula | Important distinction |
| --- | --- | --- | --- |
| New chats | `chats` | Count rows whose `started_at` is in range | A chat can contain many messages. |
| Total messages | `messages` | Count all question and answer rows created in range | A message is not a chat. The API also reports visitor questions and profile answers separately. |
| Unique visitors | `profile_interaction_events` | Count distinct non-null `visitor_id_hash` with `profile_viewed` in range | Counts browsers/devices, not identified people. |
| Product clicks | `profile_interaction_events` | Count `product_clicked` in range | One product may be shown several times and clicked zero, one, or many times. |
| Product shown | `profile_interaction_events` | Count `product_shown` in range | Created server-side once per answer/product pair; it is not inferred from a click. |
| Instagram shown | `profile_interaction_events` | Count `media_shown` where `provider=instagram` | “Shown” means attached to an answer delivered by the server, once per answer/media pair. It does not claim viewport visibility. |
| Instagram external clicks | `profile_interaction_events` | Count `media_external_clicked` where `provider=instagram` | Opening the media modal is recorded separately as `media_opened`. |
| TikTok shown | `profile_interaction_events` | Count `media_shown` where `provider=tiktok` | Independent from media opens and external clicks. |
| TikTok external clicks | `profile_interaction_events` | Count `media_external_clicked` where `provider=tiktok` | Never inferred from shown media. |
| OnlyFans images shown | `profile_interaction_events` | Count `media_shown` where `provider=onlyfans` and `media_type=image` | The adult-content gate does not create a second impression. |
| OnlyFans external clicks | `profile_interaction_events` | Count `media_external_clicked` where `provider=onlyfans` | Only recorded after a real click. |
| Provider CTR | Derived | `external_clicks / shown * 100`; zero when shown is zero | A diagnostic ratio, not a conversion or purchase. |

## Interaction event vocabulary

The public endpoint accepts only these events:

- `profile_viewed`: public profile loaded successfully.
- `product_clicked`: a product image or CTA was clicked. Metadata includes `surface` (`product_image` or `product_button`) and `destination_type` (`external_url`, `whatsapp`, or `telegram`).
- `product_shown`: created server-side when an answer containing a product card is persisted. Product identity and display fields are snapshotted so deleted or unpublished products remain in historical reports. Clients cannot submit it.
- `media_shown`: created server-side when an answer containing media is persisted. Clients cannot submit it.
- `media_opened`: visitor opened an image or started a video. For age-gated content, it is recorded only after adult confirmation; opening the gate itself is not a media view.
- `media_external_clicked`: visitor followed a provider permalink from a card or modal.
- `social_link_clicked`: visitor clicked a configured profile social icon. `provider` is the configured network key, including WhatsApp when configured.

`subject_id` identifies the product or media. `surface` identifies where the action occurred. `idempotency_key` prevents a browser retry from double-counting one action. Providers are lowercase canonical values (`instagram`, `tiktok`, `onlyfans`, `whatsapp`, and so on).

## Conversation categories

Every completed classification has exactly one primary category, optional secondary categories, a confidence score, a short summary, and evidence message IDs.

- `irrelevant_or_spam`: tests, nonsense, abuse, automation, or unrelated content.
- `profile_discovery`: biography, experience, work, projects, or identity.
- `social_engagement`: social links, following, photos, videos, or community engagement.
- `product_interest`: features, comparison, recommendation, or general product information.
- `purchase_intent`: price, availability, ordering, or explicit intent to acquire. This is not a completed purchase.
- `business_opportunity`: hiring, booking, partnership, sponsorship, or collaboration.
- `support_or_complaint`: help, problem report, complaint, or post-purchase support.
- `other_or_unclear`: legitimate conversation with insufficient evidence or no matching category.

The classifier runs after chat closure and never blocks the live response. Low-confidence results are marked `needs_review`.

## Test matrix

### Unit and feature tests

1. A first visitor message creates one chat and one question; its generated reply is a separate message.
2. A second message within the timeout reuses the chat and updates `last_activity_at`.
3. A message after 30 minutes closes the previous chat and creates a new one without requiring a retry.
4. The scheduled closer is idempotent and queues classification only once.
5. Public events reject unsupported types, foreign subjects, invalid providers, and duplicate idempotency keys.
6. Media delivered by an answer creates one `media_shown` event per answer/media pair.
7. Product, WhatsApp, provider, modal, and social-icon clicks remain distinct event types/surfaces.
8. Insights enforces authentication, ability, ownership, timezone validation, date validation, and the maximum range.
9. Every summary value equals an independent database aggregate for the same UTC interval.
10. Classification validates the structured result, retries failures, and never calls a real provider in tests.
11. Removed notification types are neither emitted nor offered while payment-failure and renewal notifications remain intact.

### Signed-in functional and visual tests

1. Open profile Insights and verify the default last-month range.
2. Visit Dashboard, Chats, and Products and verify the ten executive values, goals, product history, responsive layout, loading, empty, and partial-history states.
3. Compare every displayed value with direct SQL aggregates.
4. Open the public profile, send two messages in one chat, expire its last activity by 30 minutes in the local database, and send another message; verify two chats and correct message counts.
5. Trigger a product image click, product CTA click, WhatsApp product destination, media open, Instagram/TikTok/OnlyFans external click, and each configured social icon; verify each stored event and its surface/provider.
6. Repeat functional and visual review plus adjustments six times, checking desktop and narrow viewport states.

## Adding a new metric

1. Write a stable business definition and choose its timestamp before coding.
2. Prefer deriving the metric from an existing authoritative table. Add an event only for an interaction that is otherwise unobservable.
3. Add a finite event type and validate that its subject belongs to the profile.
4. Include a unique idempotency key and only non-sensitive metadata.
5. Add indexes for `profile_id`, timestamp, event type, provider, or visitor according to the query.
6. Add the aggregate to the Insights response without changing existing fields.
7. Add a database reconciliation query, automated tests, translations, Postman example, and a data-availability note when history cannot be backfilled.
