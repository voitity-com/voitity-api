# Testing

Run tests through Docker from the repository root.

```sh
docker compose exec app php artisan test
docker compose exec app php artisan test --filter=RelevantTest
docker compose exec app php artisan test --testsuite=Unit
docker compose exec app php artisan test --testsuite=Feature
```

Use `-T` for CI-style non-interactive execution:

```sh
docker compose exec -T app php artisan test
```

## API Tests

For API endpoint changes, add Feature tests for:

- success payload
- validation failure
- missing or wrong ability
- non-owner access
- admin access when applicable
- pagination and ordering when the endpoint lists resources

## Service Adapter Tests

For third-party service adapters, add Unit tests for:

- manager default driver
- manager named driver
- custom driver support when implemented
- service provider container binding
- DTO status helpers and `toArray`
- service wrapper delegation and persistence
- provider response mapping with faked HTTP
- provider failure and exception mapping

Never call real third-party APIs in tests.
