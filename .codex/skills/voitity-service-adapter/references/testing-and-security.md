# Testing And Security

## Test Checklist

For a new service family:

- manager returns default driver from config
- manager creates named provider driver
- custom driver via callable/class works when implemented
- service provider resolves manager and client interface
- DTO helpers work: `isSuccessful`, `isFailed`, `isPending`, `toArray`
- service wrapper delegates to client
- service wrapper persists local models only when required
- provider adapter maps HTTP success to DTO
- provider adapter maps HTTP failure to failed DTO or expected exception
- provider adapter maps exceptions to failed/error DTO or expected exception
- webhook signature validation succeeds and fails

## Test Tools

Use:

- `Http::fake()` for provider HTTP calls
- Mockery for manager/service wrapper tests
- fake storage for file or binary payloads
- config overrides for keys, base URLs, models, and defaults

Never call a real provider from PHPUnit.

## Security Rules

Do not log:

- API keys
- bearer tokens
- auth headers
- card numbers
- CVV
- raw payment method tokens
- private documents or full user content

For payments:

- do not store CVV
- do not store raw card numbers
- store provider ids and normalized statuses
- validate webhook signatures before trusting payloads
- make webhook processing idempotent
- avoid duplicate refunds or duplicate payment records

For webhooks:

- validate signature
- verify timestamp tolerance when provider supports it
- store provider event id when persistence exists
- ignore or safely handle duplicate events
- return fast and queue heavy work when needed

## Logging

Log enough context to debug without leaking secrets:

- provider/source
- local model id
- provider resource id
- normalized status
- HTTP status
- request URL without query secrets
- sanitized error message

Prefer structured logs.
