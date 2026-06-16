# Authorization Rules

Voitity uses Laravel Sanctum abilities configured in `src/config/roles.php`.

Protected endpoints should validate:

- authenticated user
- required ability
- admin access when the feature allows global access
- owner access when the user is not admin

## Owner/Admin Pattern

When a request receives a `profile_id`:

1. Load the profile or return not found.
2. If the authenticated user is admin, allow access.
3. If not admin, require `profile.user_id === auth()->id()`.
4. Return not found or forbidden consistently with existing endpoint behavior.

Use the existing controller behavior as the local source of truth. If adding
many endpoints with the same access rule, extract the logic to a policy or a
small access helper instead of duplicating it.

## Ability Tests

Feature tests should cover at least:

- user with ability and ownership succeeds
- user without ability is rejected
- non-admin non-owner is rejected
- admin succeeds when admin bypass is intended

Do not rely only on role checks. Ability middleware and ownership checks are
separate concerns.
