# API Patterns

## File Locations

- Routes: `src/routes/api/v1/api.php`
- Controllers: `src/app/Http/Controllers/api/v1`
- Requests: `src/app/Http/Requests`
- Responses: `src/app/Http/Responses`
- Tests: `src/tests/Feature` for endpoints, `src/tests/Unit` for isolated logic
- Abilities: `src/config/roles.php`
- Postman: `postman/voitity-api.postman_collection.json`
- Enums: `src/app/Enums`

## Endpoint Checklist

For a new or changed endpoint:

1. Add the route under the correct prefix.
2. Add `auth:sanctum` middleware for protected endpoints.
3. Add the exact `abilities:*` middleware.
4. Validate query/body params.
5. Validate resource existence.
6. Validate owner/admin access.
7. Use a response class when the payload is reusable or non-trivial.
8. Add tests for success, auth/ability, ownership, validation, and pagination.
9. Add Swagger annotations if the controller is documented.
10. Update Postman when the public API changes.

For schema changes, create a new migration instead of editing old migrations.

For finite string states, prefer a PHP backed enum in `src/app/Enums`, validate
with `Rule::enum(...)`, cast it from the model, and serialize the enum value in
API responses. Store the database column as a string in migrations to keep
migrations portable and easy to roll forward.

## Pagination

Default list pagination is 20 unless the user specifies another value.
Accept `page` in the query string when the endpoint is paginated.
Return pagination metadata consistently with existing response classes.

## Response Shape

Prefer explicit response classes for payloads with pagination, nested data, or
shared shape. Keep controller methods focused on validation, authorization,
query orchestration, and response construction.

## Controller Scope

Controllers should not contain provider-specific API payloads, prompt assembly,
or third-party response parsing. Put that logic in service classes under
`src/app/Classes`.
