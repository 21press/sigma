# apps/widget: visitor help bubble

TypeScript + Preact, rendered in a shadow root. Talks only to `/wp-json/sigma/v1` (the visitor REST routes).

## Build output (agreed with the plugin)

`npm run build` writes to `<repo>/build/widget/` (gitignored):

| File | What | Budget (gzip) |
|---|---|---|
| `widget.js` | Bubble. Classic script, enqueued deferred in the footer. No REST call until opened. | 8 KB |
| `panel-<hash>.js` | Panel. Loaded by `widget.js` from its own folder on first hover/focus/click. | 30 KB |
| `sizes.json` | Raw and gzip sizes. The build fails if a budget is exceeded. | |

The plugin prints this before `widget.js` (cache-safe: no nonce, nothing per user):

```js
window.SigmaWidget = { root: "https://site/wp-json/sigma/v1/" };
// optional: brand: {…same shape as /config brand…} so the launcher is right before /config loads
// only while "Show to admins only" is on: nonce → sent as X-WP-Nonce with cookies
```

## Develop

```sh
npm ci
npm run dev        # rebuilds on change + mock API on http://localhost:5178/?s=ok
npm test           # Playwright: Chromium, WebKit, Firefox, + phone profiles for @mobile tests
npm run typecheck

# against a real site running the plugin (serial: the plugin rate-limits conversations and handoffs)
DA_E2E_URL=https://your-local-site.test DA_MAILPIT=https://your-mail-catcher.test \
  npx playwright test tests/e2e-site.spec.ts --workers=1 --project=chromium --project=webkit --project=firefox

# build straight into a checkout that a local site symlinks
DA_WIDGET_OUT=/path/to/plugin/build/widget npm run build
```

The mock (`dev/mock-server.mjs`) implements the §8 visitor routes. Scenarios via `?s=`: `ok`, `aioff`, `humanoff`, `disabled`, `configfail`, `slow`, `brand`, `forcelight`, `left`. Words in a question: `limit` → 429 hour, `daily` → 429 day, `down` → 503 `ai_unavailable`, `boom` → 500, `slowly` → 9 s answer, `price` → `answered:false` with a link, `peru` → `answered:false` without links, `inject` → hostile Markdown. The dev page carries deliberately hostile theme CSS.

`tests/stacking.spec.ts` covers stacking: on top of max-z elements added before and after the widget, and the consent guard with a fixed bottom-right cookie card (dev page `?cookie=1`, `?cookie=late`, `?wall=1`, `?menu=1`, `?blocker=before`, `?bg=dark|none`). `tests/live-consent.spec.ts` (opt-in, `DA_LIVE=1`) checks the real deltatheme.com cookie card, read-only.

`tests/isolation.spec.ts` loads the four Delta preview themes (kali, kiln, nordra, quadrant) and injects the local build inside the test browser only (request interception, nothing deployed). Set `DA_SHOTS=<dir>` to save screenshots.

## Rules this code keeps

- Look and copy follow the design spec. Tokens in `src/bubble-css.ts`, components in `src/panel/styles.ts`, both in px.
- Server and model text is rendered as text nodes or through `src/panel/markdown.tsx`, which builds vnodes. There is no `innerHTML` with server data. Links are http(s) only and open in the same tab.
- Text a visitor sees goes through `t( 'English' )` from `src/i18n.ts`, always with a literal (add `/* translators: … */` before it when the text has `%s`). `npm run build` writes `includes/class-widget-strings.php` from those calls so the plugin can translate them; the translations arrive as `SigmaWidget.i18n`.
- px units only. `rem` would follow the theme's `<html>` font-size.
- Stacking: host fixed, z-index 2147483647, last child of `<body>`; re-appended once if a later max-z sibling appears. Consent guard in `src/stacking.ts` lifts the closed launcher 12px above a fixed/sticky element under it, and hides it while a full-screen consent wall covers it.
- Light/dark: `brand.scheme`, else `data-theme` (or `.dark`) on `<html>`, else the page's background tone, else `prefers-color-scheme`; flips live. Reduced motion sets durations to 0.
- Focus: desktop panel is non-modal (no trap); the full-screen phone panel (< 640px) is modal with a trap. Escape closes and returns focus to the launcher.
- Storage: token in `localStorage` (`delta-assistant:v1`, a legacy name kept so chats survive the switch-over; 24 h); transcript, view and open state in `sessionStorage`, so a same-tab doc link reopens the panel on the next page. On phones a link lets the next page start closed so the doc is visible.
- Release 2: the panel's view switch (`App.tsx`) gets a `live` view for handoff `{status:"waiting"}`. The message list already renders `agent` messages.
