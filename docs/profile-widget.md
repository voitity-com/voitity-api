# Profile widget

## Purpose

The profile widget lets an external website show a profile launcher in the bottom-right corner. The launcher contains the profile image and the localized text `Habla conmigo` or `Talk to me`. Clicking it opens the existing Bigmelo profile chat inside a mobile-width iframe.

The chat implementation is not duplicated. The widget loader is deliberately small and the complete React profile application is loaded only after the visitor opens the launcher.

## Persistence and lifecycle

Widget state is stored in `profile_widgets`:

- `profile_id` is unique and is deleted with the profile.
- `public_key` is a stable UUID used in installation code. It is a public identifier, not a credential.
- `enabled` is explicitly `false` by default.

New profiles create their widget row in the same database transaction as the profile, feature settings, and base voice. Existing profiles receive an explicitly disabled row the first time their authenticated widget settings are read. No existing widget can become public automatically.

Changing a profile alias does not change the installation code. Disabling the widget makes the public configuration endpoint return `404`, so an already installed loader stops rendering the launcher.

## API

Authenticated owner or admin endpoints:

```http
GET /api/profile/{profile}/widget
PATCH /api/profile/{profile}/widget
Content-Type: application/json

{"enabled": true}
```

They require the `profile:read` and `profile:write` Sanctum abilities respectively. A user who is not the owner receives `404`; administrators can manage any profile.

Public endpoint:

```http
GET /api/public/widgets/{publicKey}
```

It returns only the stable key, public profile identity, locale, launcher label, and an image URL. It returns `404` if the key is unknown, the widget is disabled, or the profile is inactive or unpublished. Public configuration responses use `Cache-Control: no-store` so disabling takes effect on the next page load. The endpoint uses the existing public-profile read throttle.

## Logs

Authenticated reads and updates write structured logs with actor, profile, widget, and enabled state. Rejected owner access is logged at `notice`. Public misses and unavailable widgets are logged without returning private state. Unknown public keys are stored only as a SHA-256 hash. Successful high-volume public configuration reads use `debug` to avoid noisy production logs.

Do not add access tokens, chat tokens, visitor messages, or full request bodies to widget logs.

## Installation code

The administrator generates environment-aware code:

```html
<script
  async
  src="https://bigmelo.com/widget/v1.js"
  data-bigmelo-widget="PROFILE_WIDGET_UUID"
  data-bigmelo-api="https://api.bigmelo.com"
></script>
```

The loader is versioned at `/widget/v1.js`. This keeps installed sites compatible if a future loader requires a new contract. The loader uses Shadow DOM so host styles do not alter the launcher.

The admin preview does not recreate the launcher with separate UI code. It loads this same installation script inside a sandboxed, dark simulated host page. The avatar starts in the real bottom-right position and a click opens the actual 390-pixel widget panel, with the same viewport-dependent height and mobile full-screen rule used on customer sites.

On first load it requests only the public widget configuration. The iframe is created lazily after a click and opens:

```text
https://bigmelo.com/?widget=PROFILE_WIDGET_UUID
```

Using the root page with a query parameter avoids depending on pre-generated alias directories in CloudFront. The embedded application resolves the current alias from the public key.

## Embedded chat behavior

The embedded mode:

- reuses the public `Profile` component and its message, audio, product, media, and social-link behavior;
- uses the existing mobile layout because the desktop panel is 390 pixels wide;
- becomes full-screen on host viewports of 620 pixels or less;
- skips the normal page analytics-consent overlay and SEO metadata mutation;
- records profile views with the `widget_chat` surface;
- stores the visitor chat in the Bigmelo iframe origin rather than the host origin.

The iframe includes `allow="microphone"` and a sandbox that permits scripts, forms, same-origin storage, and external-link popups. Text remains available when microphone permission is blocked.

## Host-site restrictions

Most sites need only the script tag. A restrictive host Content Security Policy may need these origins:

```text
script-src https://bigmelo.com
frame-src https://bigmelo.com
connect-src https://api.bigmelo.com
img-src https://api.bigmelo.com https://bigmelo.com
```

Some CMS editors remove `<script>` tags. The code must then be installed through custom code, header/footer injection, or a tag manager. A host `Permissions-Policy` can prevent microphone access even though the iframe requests it. Browsers that block third-party storage may not preserve a conversation after a full page reload, but the active chat continues during the current iframe session.

The production Bigmelo web response must not add `X-Frame-Options: DENY` or a `frame-ancestors 'none'` policy to embedded mode. The API can and should remain non-frameable because only JSON is requested from it.

## Deployment

The web build has two Rollup entries:

- the existing React application;
- the dependency-free widget loader emitted as `dist/widget/v1.js`.

The existing S3 and CloudFront deployment publishes both. Because `v1.js` has a file extension, the current viewer-request function does not rewrite it to `index.html`.

## Validation

Automated API coverage verifies default false state, owner/admin access, abilities, validation, public data minimization, image selection, and unavailable states. Web and admin builds validate TypeScript. The reproducible functional and visual test protocol is in `qa/profile-widget/README.md`.
