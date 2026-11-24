# Rules for contributors and coding agents

Build and test rules for this repository. See [CONTRIBUTING.md](CONTRIBUTING.md) for how to send a change.

## Code
- PHP 8.2+, WordPress 6.9+, `declare( strict_types=1 )`, namespace `TwentyOnePress\Sigma`, text domain `sigma`. WordPress Coding Standards (`npm run lint:php`).
- Admin app: React + Vite + TypeScript in `apps/admin`, using WordPress's own React (`wp-element`). Visitor widget: TypeScript, shadow DOM, in `apps/widget`, nothing heavier than Preact; `widget.js` stays under 8 KB gzip.
- Only `includes/conversations/class-repository.php` touches the plugin's tables.
- Model output is never put into `innerHTML`; it is rendered as text or through the safe Markdown renderer.
- Sanitize input, escape output, check capabilities and verify nonces on every write. Use WordPress APIs before custom code.
- Storage names keep the legacy prefix (`delta_assistant_*` options, `{prefix}da_*` tables, `da_knowledge`): never rename them. Tables change only by additive migrations.

## Tests
- `npm test` (PHP unit), `npm run test:int` (WordPress integration, Docker via wp-env) or `npm run test:int:local` (a local MySQL; see `tests/bin/int-local.mjs`).
- `npm --prefix apps/widget test` and the admin Playwright tests (`apps/admin`).
- Every change comes with tests. A change to the REST API, the stored data or the security rules needs a test that pins it.

## Git
- Work on a branch and open a pull request to `main`; another person reviews it.
- Never commit API keys, tokens, `.env` files or `wp-config.php`. Never put a key in an issue, a pull request or a log.
- Tests never send email to real addresses; use a mail catcher.
