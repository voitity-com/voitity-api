# Profile widget QA

## Scope

This protocol validates the API settings, installation snippet, isolated launcher, desktop panel, mobile full-screen chat, persistence, links, and microphone restrictions.

## Preconditions

1. API is available at `http://localhost:8000` and migrations are current.
2. Admin is available at `http://localhost:3000`.
3. Public web is available at `http://localhost:3001`.
4. A real test profile has an active avatar and is active and published.
5. The tester can sign in as the profile owner or an administrator.

## Admin test

1. Open `/dashboard/profiles/{profileId}/settings`.
2. Confirm `Funcionalidades` is selected by default and existing product/integration switches still work.
3. Open `Widget` and confirm the status is `Desactivado` for a new widget.
4. Confirm instructions describe installation, publication, CSP, CMS sanitization, JavaScript/iframe, and microphone permission.
5. Copy the code and verify it contains one public UUID, `/widget/v1.js`, and the local API URL.
6. Activate the widget. If the profile is public, status becomes `Visible` and Preview is enabled.
7. Open Preview and send a text message. If microphone permission is available, record and send a short audio.
8. Disable the widget and confirm the public endpoint and installed launcher become unavailable on the next page load.

## External host test

Serve the fixture from a different origin:

```bash
python3 -m http.server 4100 --directory qa/profile-widget/fixture
```

Open:

```text
http://127.0.0.1:4100/?key=PROFILE_WIDGET_UUID
```

Expected desktop behavior:

- a circular avatar appears at the bottom right;
- the text bubble appears immediately to its left;
- host fonts, button styles, and CSS do not change the widget;
- clicking opens a mobile-width chat panel without navigating away;
- the close button and Escape close the panel and return focus to the launcher;
- reopening preserves the active iframe and conversation;
- profile social, integration, product, and media links open correctly.

Expected mobile behavior at 390 x 844:

- the launcher stays inside safe viewport edges;
- the text bubble and avatar remain visible;
- opening the widget uses the complete viewport;
- the close button remains visible above the profile content;
- the composer, send button, message list, and avatar do not overflow horizontally;
- the on-screen keyboard does not permanently hide the composer after closing.

## Negative checks

- Disabled widget: launcher is not mounted.
- Draft, hidden, or inactive profile: launcher is not mounted.
- Invalid key: host page continues normally with no visible error.
- Broken avatar image: initial-letter fallback is visible.
- Script included twice with the same key: only one launcher appears.
- Microphone blocked: text remains operational and audio presents the existing permission message.
- Host CSS uses global `button`, `img`, and `iframe` rules: Shadow DOM keeps the widget unchanged.

## Evidence to retain

- screenshot of the admin Widget tab;
- screenshot of the closed launcher on the host fixture;
- screenshot of the open desktop panel;
- screenshot of the open 390 x 844 mobile view;
- API test output and admin/web build output;
- any browser console or API log errors with timestamp and profile ID, excluding tokens and visitor messages.
