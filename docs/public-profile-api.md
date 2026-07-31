# Public Profile API

## Purpose

The public Bigmelo website must not embed a reusable Laravel Sanctum token.
Values prefixed with `VITE_` are compiled into browser JavaScript and are
visible to every visitor. Public profile traffic therefore uses a dedicated,
unauthenticated API surface with narrowly scoped responses and rate limits.

Authenticated profile, avatar, chat, voice, and administration endpoints remain
unchanged and continue to require their existing Sanctum abilities.

## Public Endpoints

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/api/public/profiles/{alias}` | Read an active, published profile |
| `GET` | `/api/public/social-networks` | Read supported social network definitions |
| `GET` | `/api/public/profiles/{profile}/avatar` | Read only the active avatar file |
| `GET` | `/api/public/profiles/{profile}/messaging-capabilities` | Read current text/audio availability |
| `POST` | `/api/public/profiles/{profile}/messages` | Start or continue a visitor text chat |
| `POST` | `/api/public/profiles/{profile}/messages/audio` | Start or continue a visitor audio chat |

All profile-specific endpoints return `404` when the profile is inactive,
draft, hidden, deleted, or missing. This prevents the public API from
disclosing whether a non-public profile exists.

Public profile responses intentionally omit owner IDs, voice provider IDs,
publication diagnostics, failure details, and internal timestamps.

## Visitor Chat Sessions

Starting a text or audio chat does not require a chat token. A successful
response includes:

```json
{
  "data": {
    "chat_id": 42,
    "chat_token": "encrypted-value"
  }
}
```

To continue that chat, send both `chat_id` and the token:

```http
X-Bigmelo-Chat-Token: encrypted-value
```

The token is encrypted and authenticated with Laravel's application key. Its
payload is scoped to one profile and one chat and includes an expiration time.
The default lifetime is 1,440 minutes. Each successful message response issues
a refreshed token. Missing, expired, modified, or cross-profile tokens return:

```json
{
  "message": "Chat not found.",
  "code": "CHAT_SESSION_INVALID"
}
```

The public website stores `chat_id`, `chat_token`, and rendered messages in
`sessionStorage`. Closing the browser session removes them. The token must not
be placed in URLs because query strings can appear in access logs and browser
history.

Public visitors cannot list chats or retrieve arbitrary chat histories.
Authenticated owners and administrators continue to use the protected chat
endpoints.

## Rate Limits And Usage

Public profile reads are limited per source IP. The default is 120 requests per
minute.

Public text and audio messages use the existing per-IP and per-profile message
rate limiter. They also use the same subscription entitlement, reservation,
monthly limit, purchased credit, audio duration, and audio size controls as the
authenticated message endpoints.

Environment settings:

```env
PUBLIC_PROFILE_CHAT_SESSION_LIFETIME_MINUTES=1440
PUBLIC_PROFILE_READ_RATE_LIMIT_PER_MINUTE=120
```

## Deployment Order

Deploy the API before the public website:

1. Deploy the API and verify the six `/api/public` routes.
2. Verify an active, published profile returns `200` without authorization.
3. Verify an inactive or hidden profile returns `404`.
4. Deploy the website without `VITE_API_TOKEN`.
5. Verify profile, avatar, text, and audio flows.
6. Remove `VITE_API_TOKEN` from the web production secret.
7. Revoke the former public web Sanctum token.

The API and web steps are backward compatible during rollout: deploying the API
first does not change existing authenticated routes.

## Regression Tests

Run:

```sh
docker compose exec -T app php artisan test \
  tests/Feature/Http/Controllers/api/v1/PublicProfileControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/PublicProfileMessageControllerTest.php \
  tests/Unit/Classes/PublicProfiles/PublicChatSessionTest.php
```

Then run the existing profile, avatar, messaging capability, message, and chat
controller suites.
